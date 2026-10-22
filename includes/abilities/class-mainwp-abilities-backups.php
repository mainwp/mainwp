<?php
/**
 * Backup abilities share authorization and keep previews separate from writes.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Backup reads and actions share the service's authorization and refusal rules. */
class MainWP_Abilities_Backups {

    /**
     * Granular right each backup read requires, on top of the
     * manage_options gate and the owner and site checks. Backup writes
     * resolve their rights through
     * MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS instead.
     *
     * @var array<string,string>
     */
    const READ_RIGHTS = array(
        'mainwp/get-backup-status-v1'        => 'execute_backups',
        'mainwp/list-backup-history-v1'      => 'execute_backups',
        'mainwp/list-backup-destinations-v1' => 'edit_backup_tasks',
        'mainwp/get-backup-destination-v1'   => 'edit_backup_tasks',
        'mainwp/get-backup-settings-v1'      => 'manage_dashboard_settings',
        'mainwp/list-backup-tasks-v1'        => 'execute_backups',
        'mainwp/get-backup-task-v1'          => 'edit_backup_tasks',
        'mainwp/get-backup-task-run-v1'      => 'run_backup_tasks',
        'mainwp/get-site-backup-progress-v1' => 'execute_backups',
        'mainwp/list-backup-snapshots-v1'    => 'execute_backups',
        'mainwp/get-backup-snapshot-v1'      => 'execute_backups',
        'mainwp/list-restore-jobs-v1'        => 'execute_backups',
        'mainwp/get-restore-job-v1'          => 'execute_backups',
    );

    /** Writes use the operation-right map rather than the read-right map. */
    private const WRITE_ABILITIES = array(
        'mainwp/run-site-backup-v1',
        'mainwp/run-backup-task-v1',
        'mainwp/cancel-site-backup-v1',
        'mainwp/reset-backup-task-run-v1',
        'mainwp/cleanup-site-backups-v1',
        'mainwp/delete-backup-snapshot-v1',
        'mainwp/create-backup-task-v1',
        'mainwp/update-backup-task-v1',
        'mainwp/delete-backup-task-v1',
        'mainwp/pause-backup-task-v1',
        'mainwp/resume-backup-task-v1',
        'mainwp/restore-site-snapshot-v1',
        'mainwp/cancel-restore-v1',
        'mainwp/rollback-restore-v1',
        'mainwp/test-backup-destination-v1',
        'mainwp/set-default-backup-destination-v1',
        'mainwp/update-backup-destination-v1',
        'mainwp/delete-backup-destination-v1',
        'mainwp/update-backup-settings-v1',
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
        self::register_run_site_backup();
        self::register_run_backup_task();
        self::register_cancel_site_backup();
        self::register_reset_backup_task_run();
        self::register_cleanup_site_backups();
        self::register_delete_backup_snapshot();
        self::register_create_backup_task();
        self::register_update_backup_task();
        self::register_delete_backup_task();
        self::register_pause_backup_task();
        self::register_resume_backup_task();
        self::register_restore_site_snapshot();
        self::register_cancel_restore();
        self::register_rollback_restore();
        self::register_test_backup_destination();
        self::register_set_default_backup_destination();
        self::register_update_backup_destination();
        self::register_delete_backup_destination();
        self::register_update_backup_settings();
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                    'type'        => 'string',
                    'enum'        => array( 'none', 'possible', 'unknown' ),
                    'description' => 'none: the restore completed, never began changing the site, or the site reported every step as fully applied or a database-only restore as rolled back. possible: the site may be partly restored. unknown: the restore is still running, so its state is not known yet. Based on the site\'s restore report when it sent one. Older sites send no report; the value then rests on whether the site began applying the restore.',
                ),
                'partial_state_reasons'    => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'child_apply_started', 'no_files_rollback', 'db_applied_after_files', 'cancel_unconfirmed' ),
                    ),
                    'description' => 'child_apply_started: the site began applying the restore, or its report could not confirm what changed. no_files_rollback: the site may have changed files from the backup and has no automatic file rollback, or, for a site without a usable report, file changes could not be ruled out. db_applied_after_files: files are applied before the database, and the database step did not finish or could not be confirmed. cancel_unconfirmed: the site never confirmed it stopped.',
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
            // String-first validation preserves the pattern before WordPress can coerce numeric strings.
            'type'        => array( 'string', 'integer' ),
            'minimum'     => 1,
            'maximum'     => 9007199254740991,
            'pattern'     => '^[1-9][0-9]*$',
            'maxLength'   => 16,
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
            // String-first validation preserves the pattern before WordPress can coerce numeric strings.
            'type'        => array( 'string', 'integer' ),
            'minimum'     => 1,
            'maximum'     => 9007199254740991,
            'pattern'     => '^[1-9][0-9]*$',
            'maxLength'   => 16,
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
            // String-first validation preserves the pattern before WordPress can coerce numeric strings.
            'type'        => array( 'string', 'integer' ),
            'minimum'     => 1,
            'maximum'     => 9007199254740991,
            'pattern'     => '^[1-9][0-9]*$',
            'maxLength'   => 16,
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
            // Every supported page size must keep its offset within a 32-bit integer.
            'maximum'     => 21474836,
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
                    'description' => 'The Dashboard sets status complete after it downloads and records the archives. archive_integrity says whether the site\'s own hash was checked.',
                ),
                'archive_integrity' => array(
                    'type'        => 'string',
                    'enum'        => array( 'transfer_hash_only', 'child_content_hash' ),
                    'description' => 'child_content_hash: every archive recorded for this backup (for an incremental backup, every archive in its chain back to the full backup) carries a sha256 hash from the site, and each downloaded file matched it. transfer_hash_only: the stored evidence does not cover every archive: at least one archive in this backup or its chain has no hash from the site (older MainWP Child versions, or a file the site could not hash: too large to hash in time, or unreadable), or part of the chain is missing. Only the transfer was checked, against the hash the site issues with a download token, where the site uses download tokens.',
                ),
                'skipped_files'     => array(
                    'type'        => 'string',
                    'enum'        => array( 'not_reported', 'none', 'reported' ),
                    'description' => 'none: the site reported no skipped files; files excluded by the backup\'s settings are not counted as skipped. reported: the site marked the backup as partial or reported one or more files it left out, for example unreadable files or links. not_reported: the backup holds no usable skipped-file report: older MainWP Child versions and database-only backups do not send one, and a backup whose file list was missing or failed its hash check has none stored.',
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
                    'type'        => 'string',
                    'enum'        => array( 'not_requested', 'pending', 'taken', 'failed', 'pruned', 'not_attempted' ),
                    'description' => 'Status of the safety backup the Dashboard takes before the restore overwrites the site. not_requested: the restore did not ask for a safety backup, or it is a rollback. pending: the restore has not reached its safety backup step or is still running it. taken: the safety backup is kept; rollback_available says whether a rollback would be accepted. pruned: the safety backup was taken and later removed. failed: the safety backup step started but produced no backup. not_attempted: the restore failed or was cancelled before its safety backup step started, so none was made.',
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
                'description'         => __( 'Last known backup per site across the fleet: the latest built-in snapshot, a backup time the site reported, or a legacy task run that left a backup archive, with a staleness threshold, the backup health bucket and the pre-update backup check verdict. Use it to answer which sites need a backup. Does not list snapshots; use list-backup-snapshots-v1 for one site\'s history. Possible errors: none beyond authentication and rights.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
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
                        'instructions' => 'Sites with no known backup from any covered source report source none and count as stale. health comes from built-in snapshots only; update_gate uses the same evidence as the pre-update backup check. The evidence fields say what each status proves. Backups made by plugins the Dashboard cannot see are not covered.',
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                        'required'             => array( 'id', 'task_id', 'task_name', 'site_id', 'site_name', 'trigger', 'initiator_user_id', 'outcome', 'gate_verdict', 'started_at', 'finished_at', 'error', 'snapshot_recorded', 'snapshot_id', 'restore_job_id', 'ref' ),
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
                            'gate_verdict'      => array(
                                'type'        => array( 'string', 'null' ),
                                'enum'        => array( 'missing', 'pending_offsite', null ),
                                'description' => 'The pre-update backup verdict that was overridden, on gate_overridden rows: missing or pending_offsite. Null for other outcomes.',
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'ability_class'       => MainWP_Backup_Ability::class,
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
                'description'         => __( 'Built-In Backups on or off, primary backup method, on-demand retention count, concurrency limit, diagnostics flag, the pre-update backup check policy, and whether encryption is on and a recovery passphrase is set. Never returns key material. Possible errors: none beyond authentication and rights.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
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
                    'description' => 'Empty when Built-In Backups (or none) are the primary method; otherwise the backup extension slug.',
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
            // String-first validation preserves the pattern before WordPress can coerce numeric strings.
            'type'        => array( 'string', 'integer' ),
            'minimum'     => 1,
            'maximum'     => 9007199254740991,
            'pattern'     => '^[1-9][0-9]*$',
            'maxLength'   => 16,
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
        if ( ! array_key_exists( $ability, self::READ_RIGHTS ) && ! in_array( $ability, self::WRITE_ABILITIES, true ) ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
        }
        $rights = isset( self::READ_RIGHTS[ $ability ] ) ? array( self::READ_RIGHTS[ $ability ] ) : MainWP_Abilities_Util::required_rights_for_ability( $ability );
        foreach ( $rights as $required_right ) {
            if ( '' !== $required_right && ! MainWP_Abilities_Util::current_user_has_operation_right( $required_right ) ) {
                return self::safe_wp_error(
                    new \WP_Error(
                        'mainwp_insufficient_right',
                        '',
                        array(
                            'status' => 403,
                            'right'  => $required_right,
                        )
                    )
                );
            }
        }
        $right = $rights[0];
        if ( 'mainwp/update-backup-settings-v1' === $ability && ( ! MainWP_System_Utility::is_admin() || MainWP_System_Utility::is_team_control_staff() ) ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_permission_denied', '', array( 'status' => 403 ) ) );
        }
        // Global settings and destinations can affect tasks outside the caller's site scope.
        if ( in_array(
            $ability,
            array(
                'mainwp/test-backup-destination-v1',
                'mainwp/set-default-backup-destination-v1',
                'mainwp/update-backup-destination-v1',
                'mainwp/delete-backup-destination-v1',
                'mainwp/update-backup-settings-v1',
            ),
            true
        ) ) {
            $scope = MainWP_Abilities_Util::check_unrestricted_access();
            if ( is_wp_error( $scope ) ) {
                return self::safe_wp_error( $scope );
            }
        }
        if ( in_array( $ability, array( 'mainwp/test-backup-destination-v1', 'mainwp/set-default-backup-destination-v1', 'mainwp/update-backup-destination-v1', 'mainwp/delete-backup-destination-v1' ), true ) && ! \mainwp_current_user_can( 'dashboard', 'download_backups' ) ) {
            return self::safe_wp_error(
                new \WP_Error(
                    'mainwp_insufficient_right',
                    '',
                    array(
                        'status' => 403,
                        'right'  => 'download_backups',
                    )
                )
            );
        }
        $valid = self::validate_id_input( $input, in_array( $ability, self::WRITE_ABILITIES, true ) );
        if ( is_wp_error( $valid ) ) {
            return $valid;
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
     * Authorization and reads must name one exact id, without numeric coercion.
     *
     * JSON integers and canonical decimal digit strings are exact through 2^53 - 1.
     * A float or another numeric string shape can round to a different identity.
     *
     * @param mixed $value Caller identifier.
     * @return int|null
     */
    private static function positive_int( $value ): ?int {
        if ( is_int( $value ) ) {
            return $value >= 1 && $value <= 9007199254740991 ? $value : null;
        }
        if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
            return null;
        }
        $length = strlen( $value );
        return $length < 16 || ( 16 === $length && strcmp( $value, '9007199254740991' ) <= 0 ) ? (int) $value : null;
    }

    /**
     * WordPress accepts whole floats as integers, so schemas alone cannot protect identity.
     *
     * @param mixed $input Raw ability input.
     * @param bool  $selections Whether selections are write targets.
     * @return true|\WP_Error
     */
    public static function validate_id_input( $input, bool $selections = false ) {
        if ( is_array( $input ) ) {
            foreach ( array( 'task_id', 'snapshot_id', 'restore_job_id', 'destination_id' ) as $key ) {
                if ( array_key_exists( $key, $input ) && null === self::positive_int( $input[ $key ] ) ) {
                    return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
                }
            }
            if ( array_key_exists( 'site_id_or_domain', $input ) ) {
                $site = $input['site_id_or_domain'];
                // Numeric-looking strings name ids; domains and URLs retain the house resolver.
                if ( '' === $site || ( ( ! is_string( $site ) || is_numeric( $site ) ) && null === self::positive_int( $site ) ) ) {
                    return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
                }
            }
            // Read filters retain REST's list coercion; write selections must preserve raw identities.
            foreach ( $selections ? array( 'site_ids_or_domains', 'group_ids', 'destination_ids', 'expected_snapshot_ids' ) : array() as $key ) {
                if ( ! array_key_exists( $key, $input ) ) {
                    continue;
                }
                if ( ! is_array( $input[ $key ] ) ) {
                    return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
                }
                foreach ( $input[ $key ] as $id ) {
                    $valid = 'site_ids_or_domains' === $key ? self::validate_id_input( array( 'site_id_or_domain' => $id ) ) : ( null !== self::positive_int( $id ) );
                    if ( is_wp_error( $valid ) || false === $valid ) {
                        return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
                    }
                }
            }
        }
        return true;
    }

    /**
     * Route validation must reject schema failures before core's input sanitization.
     *
     * @param array $routes Registered REST endpoints.
     * @return array
     */
    public static function validate_rest_run_routes( array $routes ): array {
        foreach ( $routes as $route => &$handlers ) {
            if ( ! str_starts_with( $route, '/wp-abilities/v1/abilities/' ) || ! str_ends_with( $route, '/run' ) ) {
                continue;
            }
            foreach ( $handlers as &$handler ) {
                if ( isset( $handler['args']['input'] ) ) {
                    // Core keeps sanitizing after a bad name, so the input sanitizer can receive an array name; validating name first stops the request before that.
                    if ( isset( $handler['args']['name'] ) && empty( $handler['args']['name']['validate_callback'] ) ) {
                        $handler['args']['name']['validate_callback'] = 'rest_validate_request_arg';
                    }
                    $handler['args']['input']['validate_callback'] = array( self::class, 'validate_rest_input' );
                }
            }
            unset( $handler );
        }
        unset( $handlers );
        return $routes;
    }

    /**
     * Registered backup abilities need their schema checked before REST coercion.
     *
     * Query and body parameters outrank the route, so `name` can be any shape a request carries.
     * Other abilities retain the controller's default input check.
     *
     * @param mixed            $input Request input.
     * @param \WP_REST_Request $request Current REST request.
     * @param string           $param Parameter name.
     * @return true|\WP_Error
     */
    public static function validate_rest_input( $input, \WP_REST_Request $request, string $param ) {
        $name = $request->get_param( 'name' );
        if ( ! is_string( $name ) || ( ! array_key_exists( $name, self::READ_RIGHTS ) && ! in_array( $name, self::WRITE_ABILITIES, true ) ) || ! wp_has_ability( $name ) ) {
            return rest_validate_request_arg( $input, $request, $param );
        }
        $ability     = wp_get_ability( $name );
        $annotations = $ability->get_meta_item( 'annotations' );
        $method      = ! empty( $annotations['readonly'] ) ? 'GET' : ( ! empty( $annotations['destructive'] ) && ! empty( $annotations['idempotent'] ) ? 'DELETE' : 'POST' );
        if ( $request->get_method() !== $method ) {
            // Core's method refusal precedes the ability-specific input check.
            return rest_validate_request_arg( $input, $request, $param );
        }
        // DELETE bodies are normalized into the query before the controller executes.
        $params = in_array( $request->get_method(), array( 'GET', 'DELETE' ), true ) ? $request->get_query_params() : $request->get_json_params();
        if ( 'DELETE' === $request->get_method() && ! isset( $params['input'] ) ) {
            // Body normalization runs after route validation, so inspect the same carrier here.
            $params = $request->get_json_params();
        }
        $input = $params['input'] ?? null;
        if ( 'mainwp/update-backup-destination-v1' === $name && is_array( $input ) && array_diff_key( $input, $ability->get_input_schema()['properties'] ) ) {
            // Core's ability validator preserves the documented refusal for connection fields.
            return true;
        }
        $valid = rest_validate_value_from_schema( $ability->normalize_input( $input ), $ability->get_input_schema(), $param );
        return is_wp_error( $valid ) ? $valid : ( in_array( $name, self::WRITE_ABILITIES, true ) ? self::validate_id_input( $input, true ) : true );
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
        if ( 'mainwp_insufficient_right' === $safe['code'] && is_array( $source ) && isset( $source['right'] ) && in_array( $source['right'], array_merge( self::READ_RIGHTS, MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS, array( 'restore_backups' ) ), true ) ) {
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

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_run_site_backup(): void {
        wp_register_ability(
            'mainwp/run-site-backup-v1',
            array(
                'label'               => __( 'Run Site Backup', 'mainwp' ),
                'description'         => __( 'Start an on-demand full backup of one site now (Back Up Now). Returns a handle; the backup continues under cron. The backup uploads to the destinations of the scheduled backup tasks that include the site, or to the Default destination when no task includes it; dry_run returns them as destinations. Poll get-site-backup-progress-v1 with the returned poll.input until on_demand.status is completed or failed. Once the snapshot lands, on-demand snapshots beyond the retention count are pruned; dry_run lists them. Possible errors: mainwp_backup_legacy_disabled, mainwp_backup_site_disconnected, mainwp_backup_already_running, mainwp_backup_start_failed, mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::run_site_backup_input_schema(),
                'output_schema'       => self::run_site_backup_output_schema(),
                'execute_callback'    => array( self::class, 'execute_run_site_backup' ),
                'permission_callback' => self::permission_callback( 'mainwp/run-site-backup-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Changes a child site and can prune older on-demand snapshots. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. not_started means no run slot was free: nothing starts on its own, call again later. already_running means the call attached to an open run, or to one that finished seconds ago; poll for its result.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function run_site_backup_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'site_id_or_domain',
            ),
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function run_site_backup_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'run_open',
                    'destinations',
                    'retention_keep',
                    'would_prune_snapshot_ids',
                ),
                'properties'           => array(
                    'site_id'                  => array(
                        'type' => 'integer',
                    ),
                    'run_open'                 => array(
                        'type'        => 'boolean',
                        'description' => 'An on-demand run is already open; confirm would attach to it instead of starting another.',
                    ),
                    'destinations'             => array(
                        'description' => 'Where the backup uploads, besides the copy kept on the Dashboard: the destinations of the scheduled backup tasks that include the site, or the Default destination when no task includes it. Empty means Dashboard only.',
                        'type'        => 'array',
                        'items'       => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'id',
                                'name',
                                'provider',
                            ),
                            'properties'           => array(
                                'id'       => array(
                                    'type' => 'integer',
                                ),
                                'name'     => array(
                                    'type'      => 'string',
                                    'maxLength' => 255,
                                ),
                                'provider' => array(
                                    'type' => 'string',
                                    'enum' => array(
                                        's3',
                                        'gcs',
                                        'dropbox',
                                        'sftp',
                                        'local',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'retention_keep'           => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'would_prune_snapshot_ids' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'status',
                    'started_at',
                    'since',
                    'poll',
                ),
                'properties'           => array(
                    'site_id'    => array(
                        'type' => 'integer',
                    ),
                    'status'     => array(
                        'type'        => 'string',
                        'enum'        => array(
                            'started',
                            'already_running',
                            'not_started',
                        ),
                        'description' => 'already_running: an on-demand backup of this site was open, or finished seconds ago, and this call attached to it. not_started: no run slot was free; nothing starts on its own, call this ability again later.',
                    ),
                    'started_at' => self::ts_schema(),
                    'since'      => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => 'Opaque handle for get-site-backup-progress-v1. Pass it back exactly as returned.',
                    ),
                    'poll'       => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array(
                            'ability',
                            'input',
                            'interval_seconds',
                        ),
                        'properties'           => array(
                            'ability'          => array(
                                'type' => 'string',
                                'enum' => array(
                                    'mainwp/get-site-backup-progress-v1',
                                ),
                            ),
                            'input'            => array(
                                'type'                 => 'object',
                                'additionalProperties' => false,
                                'required'             => array(
                                    'site_id_or_domain',
                                    'since',
                                ),
                                'properties'           => array(
                                    'site_id_or_domain' => array(
                                        'type' => 'integer',
                                    ),
                                    'since'             => array(
                                        'type' => 'integer',
                                    ),
                                ),
                            ),
                            'interval_seconds' => array(
                                'type' => 'integer',
                                'enum' => array(
                                    30,
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_run_site_backup( $input ) {
        return self::execute_write( 'run-site-backup', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_run_backup_task(): void {
        wp_register_ability(
            'mainwp/run-backup-task-v1',
            array(
                'label'               => __( 'Run Backup Task', 'mainwp' ),
                'description'         => __( 'Start a scheduled task now for all its sites (Run Now). Returns a handle; cron continues the run. Poll get-backup-task-run-v1 with the task_id. The task\'s retention applies after each site\'s snapshot lands; dry_run lists what would be pruned per site. Possible errors: mainwp_backup_legacy_disabled, mainwp_backup_already_running, mainwp_backup_task_paused, mainwp_backup_no_sites, mainwp_backup_start_failed, mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::run_backup_task_input_schema(),
                'output_schema'       => self::run_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_run_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/run-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Changes child sites and can prune snapshots. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. not_started means no run slot was free: nothing starts on its own, call again later. already_running means the call attached to an open run, or to one that finished seconds ago; poll for its result.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function run_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function run_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'run_open',
                    'site_ids',
                    'stale_site_ids',
                    'destinations',
                    'retention_impact',
                ),
                'properties'           => array(
                    'task_id'          => array(
                        'type' => 'integer',
                    ),
                    'run_open'         => array(
                        'type' => 'boolean',
                    ),
                    'site_ids'         => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                    'stale_site_ids'   => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'integer',
                        ),
                        'description' => 'Sites in the task that no longer resolve and will be skipped.',
                    ),
                    'destinations'     => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'id',
                                'name',
                                'provider',
                            ),
                            'properties'           => array(
                                'id'       => array(
                                    'type' => 'integer',
                                ),
                                'name'     => array(
                                    'type'      => 'string',
                                    'maxLength' => 255,
                                ),
                                'provider' => array(
                                    'type' => 'string',
                                    'enum' => array(
                                        's3',
                                        'gcs',
                                        'dropbox',
                                        'sftp',
                                        'local',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'retention_impact' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'site_id',
                                'would_prune_snapshot_ids',
                                'reasons',
                            ),
                            'properties'           => array(
                                'site_id'                  => array(
                                    'type' => 'integer',
                                ),
                                'would_prune_snapshot_ids' => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'integer',
                                    ),
                                ),
                                'reasons'                  => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'string',
                                        'enum' => array(
                                            'age',
                                            'count',
                                            'storage_cap',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'status',
                    'started_at',
                    'poll',
                ),
                'properties'           => array(
                    'task_id'    => array(
                        'type' => 'integer',
                    ),
                    'status'     => array(
                        'type'        => 'string',
                        'enum'        => array(
                            'started',
                            'already_running',
                            'not_started',
                        ),
                        'description' => 'already_running: a run of this task was open, or finished seconds ago, and this call attached to it. not_started: no run slot was free; nothing starts on its own, call this ability again later.',
                    ),
                    'started_at' => self::ts_schema(),
                    'poll'       => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array(
                            'ability',
                            'input',
                            'interval_seconds',
                        ),
                        'properties'           => array(
                            'ability'          => array(
                                'type' => 'string',
                                'enum' => array(
                                    'mainwp/get-backup-task-run-v1',
                                ),
                            ),
                            'input'            => array(
                                'type'                 => 'object',
                                'additionalProperties' => false,
                                'required'             => array(
                                    'task_id',
                                ),
                                'properties'           => array(
                                    'task_id' => array(
                                        'type' => 'integer',
                                    ),
                                ),
                            ),
                            'interval_seconds' => array(
                                'type' => 'integer',
                                'enum' => array(
                                    60,
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_run_backup_task( $input ) {
        return self::execute_write( 'run-backup-task', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_cancel_site_backup(): void {
        wp_register_ability(
            'mainwp/cancel-site-backup-v1',
            array(
                'label'               => __( 'Cancel Site Backup', 'mainwp' ),
                'description'         => __( 'Ask the site to stop its open on-demand backup. The result says what happened: cancelled, already_finished (the Dashboard still records the snapshot), already_failed, not_running, no_active_on_site, or busy (try again). Does not cancel scheduled task runs; use reset-backup-task-run-v1 for a stalled one. Possible errors: mainwp_operation_failed, mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::cancel_site_backup_input_schema(),
                'output_schema'       => self::cancel_site_backup_output_schema(),
                'execute_callback'    => array( self::class, 'execute_cancel_site_backup' ),
                'permission_callback' => self::permission_callback( 'mainwp/cancel-site-backup-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. busy while the backup is still starting is normal; retry after the next progress read.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function cancel_site_backup_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'site_id_or_domain',
            ),
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function cancel_site_backup_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'run_open',
                    'child_task_known',
                ),
                'properties'           => array(
                    'site_id'          => array(
                        'type' => 'integer',
                    ),
                    'run_open'         => array(
                        'type' => 'boolean',
                    ),
                    'child_task_known' => array(
                        'type'        => 'boolean',
                        'description' => 'False while the backup is starting and the site has not reported its task id yet; confirm then answers busy without contacting the site.',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'result',
                    'message',
                ),
                'properties'           => array(
                    'site_id' => array(
                        'type' => 'integer',
                    ),
                    'result'  => array(
                        'type'        => 'string',
                        'enum'        => array(
                            'cancelled',
                            'already_finished',
                            'already_failed',
                            'not_running',
                            'no_active_on_site',
                            'busy',
                        ),
                        'description' => 'cancelled: the site stopped the backup. already_finished: the site had completed it before the cancel arrived; the Dashboard still collects and records it. already_failed: the site had already failed it; the Dashboard records the failure. not_running: no on-demand backup is open on the Dashboard. no_active_on_site: the Dashboard has an open run but the site reports no such backup; the run closes through the stall reset. busy: the site could not stop right now, or its task id is not known yet; try again.',
                    ),
                    'message' => array(
                        'type'        => 'string',
                        'maxLength'   => 500,
                        'description' => 'Fixed text per result value.',
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_cancel_site_backup( $input ) {
        return self::execute_write( 'cancel-site-backup', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_reset_backup_task_run(): void {
        wp_register_ability(
            'mainwp/reset-backup-task-run-v1',
            array(
                'label'               => __( 'Reset Backup Task Run', 'mainwp' ),
                'description'         => __( 'Close a task run that has been open for more than an hour without finishing: asks each site with a known backup task to stop, then marks the run complete on the Dashboard. Refused before the stall threshold. Possible errors: mainwp_backup_not_stalled, mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::reset_backup_task_run_input_schema(),
                'output_schema'       => self::reset_backup_task_run_output_schema(),
                'execute_callback'    => array( self::class, 'execute_reset_backup_task_run' ),
                'permission_callback' => self::permission_callback( 'mainwp/reset-backup-task-run-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. Sites that did not confirm the stop are counted in sites_uncancelled; their backup may still be running on the site.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function reset_backup_task_run_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function reset_backup_task_run_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'run_open',
                    'started_at',
                    'stalled_for_seconds',
                    'stall_threshold_seconds',
                    'site_ids',
                ),
                'properties'           => array(
                    'task_id'                 => array(
                        'type' => 'integer',
                    ),
                    'run_open'                => array(
                        'type' => 'boolean',
                    ),
                    'started_at'              => self::ts_schema(),
                    'stalled_for_seconds'     => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                    ),
                    'stall_threshold_seconds' => array(
                        'type' => 'integer',
                        'enum' => array(
                            3600,
                        ),
                    ),
                    'site_ids'                => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'integer',
                        ),
                        'description' => 'Sites in the run. A site whose backup task id is not known gets no request and is counted in sites_uncancelled.',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'status',
                    'sites_cancelled',
                    'sites_uncancelled',
                ),
                'properties'           => array(
                    'task_id'           => array(
                        'type' => 'integer',
                    ),
                    'status'            => array(
                        'type'        => 'string',
                        'enum'        => array(
                            'reset',
                        ),
                        'description' => 'The run\'s status after this action: closed on the Dashboard.',
                    ),
                    'sites_cancelled'   => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => 'Sites that confirmed they stopped.',
                    ),
                    'sites_uncancelled' => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => 'Sites that did not confirm the stop, including sites whose backup task id was unknown (no request is sent then). The Dashboard closed the run anyway; their backup may still be running on the site.',
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_reset_backup_task_run( $input ) {
        return self::execute_write( 'reset-backup-task-run', $input );
    }

    /** Register snapshot deletion with the house consent and exact-id rules. */
    private static function register_delete_backup_snapshot(): void {
        wp_register_ability(
            'mainwp/delete-backup-snapshot-v1',
            array(
                'label'               => __( 'Delete Backup Snapshot', 'mainwp' ),
                'description'         => __( 'Delete one snapshot and every snapshot that depends on it. Preview the ids first, then confirm with expected_snapshot_ids. When delete_cloud_copies is true the cloud deletes are queued and run in the background. Possible errors: mainwp_backup_snapshot_not_found, mainwp_site_not_found, mainwp_backup_legacy_disabled, mainwp_invalid_input.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::delete_backup_snapshot_input_schema(),
                'output_schema'       => self::delete_backup_snapshot_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_backup_snapshot' ),
                'permission_callback' => self::permission_callback( 'mainwp/delete-backup-snapshot-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. Show every previewed snapshot id, the cloud choice and warnings before confirming with expected_snapshot_ids.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_delete_backup_snapshot( $input ) {
        return self::execute_write( 'delete-backup-snapshot', $input );
    }

    /**
     * Closed consent input.
     *
     * @return array
     */
    private static function delete_backup_snapshot_input_schema(): array {
        $schema = self::restore_site_snapshot_input_schema();
        unset( $schema['properties']['take_safety_backup'], $schema['properties']['preserve_orders'] );
        $schema['properties']['expected_snapshot_ids'] = array(
            'type'        => 'array',
            'minItems'    => 1,
            'uniqueItems' => true,
            'items'       => self::snapshot_id_schema(),
            'description' => 'Every snapshot id returned by dry_run. Required when confirm is true.',
        );
        $schema['properties']['delete_cloud_copies']   = array(
            'type'        => 'boolean',
            'description' => 'Also delete cloud copies. Omit to use the backup task\'s setting. When false, the cloud copies stay in storage and MainWP stops listing them.',
        );
        return $schema;
    }

    /**
     * Closed preview and exact deletion outcomes.
     *
     * @return array
     */
    private static function delete_backup_snapshot_output_schema(): array {
        $ids             = array(
            'type'  => 'array',
            'items' => array(
                'type'    => 'integer',
                'minimum' => 1,
            ),
        );
        $effect          = array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id', 'snapshot_id', 'snapshot_ids', 'delete_cloud_copies', 'update_gate_warning' ),
            'properties'           => array(
                'site_id'             => array( 'type' => 'integer' ),
                'snapshot_id'         => array( 'type' => 'integer' ),
                'snapshot_ids'        => $ids,
                'delete_cloud_copies' => array( 'type' => 'boolean' ),
                'update_gate_warning' => array( 'type' => 'boolean' ),
            ),
        );
        $preview         = self::write_output_schema(
            $effect,
            array(
                'type'       => 'object',
                'required'   => array(),
                'properties' => array(),
            )
        )['oneOf'][0];
        $preview['type'] = array( 'object', 'null' );
        return self::write_output_schema(
            $effect,
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'site_id', 'snapshot_id', 'status', 'deleted_ids', 'remaining_ids', 'cloud_deletes_queued', 'bytes_pruned', 'errors', 'preview' ),
                'properties'           => array(
                    'site_id'              => array( 'type' => 'integer' ),
                    'snapshot_id'          => array( 'type' => 'integer' ),
                    'status'               => array(
                        'type' => 'string',
                        'enum' => array( 'complete', 'partial', 'refused', 'changed' ),
                    ),
                    'deleted_ids'          => $ids,
                    'remaining_ids'        => $ids,
                    'cloud_deletes_queued' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'snapshot_id', 'destination_id', 'destination_name' ),
                            'properties'           => array(
                                'snapshot_id'      => array( 'type' => 'integer' ),
                                'destination_id'   => array( 'type' => 'integer' ),
                                'destination_name' => array( 'type' => 'string' ),
                            ),
                        ),
                    ),
                    'bytes_pruned'         => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                    ),
                    'errors'               => array(
                        'type'  => 'array',
                        'items' => self::safe_error_schema(),
                    ),
                    'preview'              => $preview,
                ),
            )
        );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_cleanup_site_backups(): void {
        wp_register_ability(
            'mainwp/cleanup-site-backups-v1',
            array(
                'label'               => __( 'Clean Up Site Backups', 'mainwp' ),
                'description'         => __( 'Free Dashboard storage for one site (the Snapshots page\'s Clean Up): removes orphaned snapshot folders, snapshots of deleted tasks with their cloud copies, broken incremental chains, and on-demand backups beyond keep. Does not touch snapshots of existing scheduled tasks. dry_run lists exactly what would go. Possible errors: mainwp_site_not_found, mainwp_backup_legacy_disabled.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::cleanup_site_backups_input_schema(),
                'output_schema'       => self::cleanup_site_backups_output_schema(),
                'execute_callback'    => array( self::class, 'execute_cleanup_site_backups' ),
                'permission_callback' => self::permission_callback( 'mainwp/cleanup-site-backups-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Destructive. Requires confirm:true or dry_run:true. Call dry_run first and show the snapshot ids to the user.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function cleanup_site_backups_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'site_id_or_domain',
            ),
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'keep'              => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'description' => 'On-demand backups to keep. Omit to use the on-demand retention count, as the Snapshots page does when the field is empty.',
                ),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function cleanup_site_backups_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'keep',
                    'orphan_dirs',
                    'orphaned_task_snapshot_ids',
                    'on_demand_units',
                    'bytes_to_prune',
                ),
                'properties'           => array(
                    'site_id'                    => array(
                        'type' => 'integer',
                    ),
                    'keep'                       => array(
                        'type' => 'integer',
                    ),
                    'orphan_dirs'                => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => 'Snapshot folders with no record and no open run, idle at least 6 hours.',
                    ),
                    'orphaned_task_snapshot_ids' => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'integer',
                        ),
                        'description' => 'Snapshots whose task was deleted. Their cloud copies are deleted too.',
                    ),
                    'on_demand_units'            => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'snapshot_ids',
                                'reason',
                            ),
                            'properties'           => array(
                                'snapshot_ids' => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'integer',
                                    ),
                                ),
                                'reason'       => array(
                                    'type' => 'string',
                                    'enum' => array(
                                        'broken_chain',
                                        'beyond_keep',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'bytes_to_prune'             => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'site_id',
                    'orphan_dirs_removed',
                    'units_pruned',
                    'snapshots_pruned',
                    'deleted_ids',
                    'remaining_ids',
                    'cloud_deletes_queued',
                    'orphaned_snapshots_pruned',
                    'bytes_pruned',
                    'errors',
                ),
                'properties'           => array(
                    'site_id'                   => array(
                        'type' => 'integer',
                    ),
                    'orphan_dirs_removed'       => array(
                        'type' => 'integer',
                    ),
                    'units_pruned'              => array(
                        'type' => 'integer',
                    ),
                    'snapshots_pruned'          => array(
                        'type' => 'integer',
                    ),
                    'orphaned_snapshots_pruned' => array(
                        'type' => 'integer',
                    ),
                    'bytes_pruned'              => array(
                        'type' => 'integer',
                    ),
                    'deleted_ids'               => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                    ),
                    'remaining_ids'             => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                    ),
                    'cloud_deletes_queued'      => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'snapshot_id', 'destination_id' ),
                            'properties'           => array(
                                'snapshot_id'    => array( 'type' => 'integer' ),
                                'destination_id' => array( 'type' => 'integer' ),
                            ),
                        ),
                    ),
                    'errors'                    => array(
                        'type'  => 'array',
                        'items' => self::safe_error_schema(),
                    ),
                ),
            )
        );
    }

    /**
     * Retain prune reasons while excluding local paths and filenames.
     *
     * @param mixed $error Prune diagnostic or registry error.
     * @return array Safe code and message.
     */
    public static function cleanup_error( $error ): array {
        if ( is_array( $error ) ) {
            return MainWP_Backup_Operations::safe_error( (string) ( $error['code'] ?? '' ) );
        }
        $safe = MainWP_Backup_Operations::safe_error( 'mainwp_operation_failed' );
        if ( is_string( $error ) ) {
            if ( str_contains( $error, '/' ) || str_contains( $error, '\\' ) || str_starts_with( $error, 'Could not remove ' ) || str_starts_with( $error, 'Refused to remove ' ) ) {
                $error = __( 'A local backup file or folder could not be removed. Check the backup diagnostics and try again.', 'mainwp' );
            }
            $safe['message'] = mb_strlen( $error, 'UTF-8' ) > 500 ? mb_substr( $error, 0, 499, 'UTF-8' ) . '…' : $error;
        }
        return $safe;
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_cleanup_site_backups( $input ) {
        return self::execute_write( 'cleanup-site-backups', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_create_backup_task(): void {
        wp_register_ability(
            'mainwp/create-backup-task-v1',
            array(
                'label'               => __( 'Create Backup Task', 'mainwp' ),
                'description'         => __( 'Create a scheduled backup task: name, type, schedule, sites or groups, destinations, retention, archive format and excludes. dry_run returns the normalized task and its first run time. Possible errors: mainwp_invalid_input, mainwp_site_not_found, mainwp_backup_destination_not_found, mainwp_backup_legacy_disabled.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::create_backup_task_input_schema(),
                'output_schema'       => self::create_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_create_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/create-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. Call dry_run first and show the normalized task to the user. interval schedules need interval_hours; daily, weekly and monthly without time_of_day run whenever the interval has elapsed.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function create_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'name',
                'type',
                'schedule',
            ),
            'properties'           => array(
                'name'                => array(
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                ),
                'type'                => array(
                    'type' => 'string',
                    'enum' => array(
                        'full',
                        'db',
                        'files',
                        'incremental',
                    ),
                ),
                'schedule'            => array(
                    'type' => 'string',
                    'enum' => array(
                        'daily',
                        'weekly',
                        'monthly',
                        'interval',
                    ),
                ),
                'interval_hours'      => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 8760,
                    'description' => 'Interval schedules only.',
                ),
                'time_of_day'         => array(
                    'type'        => 'string',
                    'pattern'     => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
                    'description' => 'Daily, weekly and monthly schedules, Dashboard timezone.',
                ),
                'day_of_week'         => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                    'maximum' => 6,
                ),
                'day_of_month'        => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 28,
                ),
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'maxItems'    => 1000,
                    'uniqueItems' => true,
                    'default'     => array(),
                    'items'       => self::site_id_or_domain_schema(),
                    'description' => 'Sites to back up. Stored as site IDs.',
                ),
                'group_ids'           => array(
                    'type'        => 'array',
                    'maxItems'    => 200,
                    'uniqueItems' => true,
                    'default'     => array(),
                    'items'       => array_merge(
                        self::task_id_schema(),
                        array(
                            'description' => 'Group ID.',
                        )
                    ),
                ),
                'destination_ids'     => array(
                    'type'        => 'array',
                    'maxItems'    => 20,
                    'uniqueItems' => true,
                    'default'     => array(),
                    'items'       => self::destination_id_schema(),
                ),
                'retention'           => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'count'               => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 1000,
                        ),
                        'days'                => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 3650,
                        ),
                        'storage_mb'          => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 104857600,
                        ),
                        'delete_cloud_copies' => array(
                            'type' => 'boolean',
                        ),
                        'max_chain_length'    => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 1000,
                        ),
                    ),
                ),
                'archive_format'      => array(
                    'type'    => 'string',
                    'enum'    => array(
                        'site',
                        'global',
                        'zip',
                        'tar',
                        'tar.gz',
                        'tar.bz2',
                    ),
                    'default' => 'site',
                ),
                'exclude'             => array(
                    'type'     => 'array',
                    'maxItems' => 200,
                    'default'  => array(),
                    'items'    => array(
                        'type'      => 'string',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ),
                ),
                'exclude_flags'       => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
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
                'archive_tuning'      => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'max_file_descriptors_override' => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors_auto'     => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors'          => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => 100000,
                        ),
                        'load_files_before_zip'         => array(
                            'type' => 'boolean',
                        ),
                        'zip_force_override'            => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'dry_run'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function create_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'normalized',
                    'resolved_site_ids',
                    'next_run_at',
                ),
                'properties'           => array(
                    'normalized'        => self::task_fields_schema(),
                    'resolved_site_ids' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                    'next_run_at'       => self::ts_schema(),
                ),
            ),
            self::get_backup_task_output_schema()
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_create_backup_task( $input ) {
        return self::execute_write( 'create-backup-task', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_update_backup_task(): void {
        wp_register_ability(
            'mainwp/update-backup-task-v1',
            array(
                'label'               => __( 'Update Backup Task', 'mainwp' ),
                'description'         => __( 'Change any subset of a task\'s fields; omitted fields keep their values. Saving makes you the task owner, as the Backups page does. Lowering retention prunes on the next sweep; dry_run lists the affected snapshots and the owner change. Possible errors: mainwp_invalid_input, mainwp_backup_task_not_found, mainwp_site_not_found, mainwp_backup_legacy_disabled.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::update_backup_task_input_schema(),
                'output_schema'       => self::update_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/update-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Changes the schedule and retention of backups. Requires confirm:true or dry_run:true. Call dry_run first and show the diff and retention impact to the user. time_of_day null clears the time.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function update_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id'             => self::task_id_schema(),
                'name'                => array(
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                ),
                'type'                => array(
                    'type' => 'string',
                    'enum' => array(
                        'full',
                        'db',
                        'files',
                        'incremental',
                    ),
                ),
                'schedule'            => array(
                    'type' => 'string',
                    'enum' => array(
                        'daily',
                        'weekly',
                        'monthly',
                        'interval',
                    ),
                ),
                'interval_hours'      => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 8760,
                ),
                'time_of_day'         => array(
                    'type'        => array(
                        'string',
                        'null',
                    ),
                    'pattern'     => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
                    'description' => 'null clears the time: a daily, weekly or monthly task then runs in elapsed mode (stored \'\'), as leaving the field empty on the task form does. Omit to keep the stored time.',
                ),
                'day_of_week'         => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                    'maximum' => 6,
                ),
                'day_of_month'        => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 28,
                ),
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'maxItems'    => 1000,
                    'uniqueItems' => true,
                    'items'       => self::site_id_or_domain_schema(),
                    'description' => 'Replaces the task\'s sites. Stored as site IDs.',
                ),
                'group_ids'           => array(
                    'type'        => 'array',
                    'maxItems'    => 200,
                    'uniqueItems' => true,
                    'items'       => array_merge(
                        self::task_id_schema(),
                        array(
                            'description' => 'Group ID.',
                        )
                    ),
                ),
                'destination_ids'     => array(
                    'type'        => 'array',
                    'maxItems'    => 20,
                    'uniqueItems' => true,
                    'items'       => self::destination_id_schema(),
                ),
                'retention'           => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'count'               => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 1000,
                        ),
                        'days'                => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 3650,
                        ),
                        'storage_mb'          => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 104857600,
                        ),
                        'delete_cloud_copies' => array(
                            'type' => 'boolean',
                        ),
                        'max_chain_length'    => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 1000,
                        ),
                    ),
                ),
                'archive_format'      => array(
                    'type' => 'string',
                    'enum' => array(
                        'site',
                        'global',
                        'zip',
                        'tar',
                        'tar.gz',
                        'tar.bz2',
                    ),
                ),
                'exclude'             => array(
                    'type'     => 'array',
                    'maxItems' => 200,
                    'items'    => array(
                        'type'      => 'string',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ),
                ),
                'exclude_flags'       => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
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
                'archive_tuning'      => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'max_file_descriptors_override' => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors_auto'     => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors'          => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => 100000,
                        ),
                        'load_files_before_zip'         => array(
                            'type' => 'boolean',
                        ),
                        'zip_force_override'            => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'dry_run'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function update_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'changes',
                    'retention_impact',
                    'next_run_at',
                    'owner_changes_to_user_id',
                ),
                'properties'           => array(
                    'task_id'                  => array(
                        'type' => 'integer',
                    ),
                    'changes'                  => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'field',
                                'from',
                                'to',
                            ),
                            'properties'           => array(
                                'field' => array(
                                    'type'      => 'string',
                                    'maxLength' => 64,
                                ),
                                'from'  => array(
                                    'type' => array(
                                        'string',
                                        'integer',
                                        'boolean',
                                        'array',
                                        'null',
                                    ),
                                ),
                                'to'    => array(
                                    'type' => array(
                                        'string',
                                        'integer',
                                        'boolean',
                                        'array',
                                        'null',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'retention_impact'         => array(
                        'type'        => 'array',
                        'items'       => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'site_id',
                                'would_prune_snapshot_ids',
                                'reasons',
                            ),
                            'properties'           => array(
                                'site_id'                  => array(
                                    'type' => 'integer',
                                ),
                                'would_prune_snapshot_ids' => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'integer',
                                    ),
                                ),
                                'reasons'                  => array(
                                    'type'  => 'array',
                                    'items' => array(
                                        'type' => 'string',
                                        'enum' => array(
                                            'age',
                                            'count',
                                            'storage_cap',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        'description' => 'Pruned on the next retention sweep after the change.',
                    ),
                    'next_run_at'              => self::ts_schema(),
                    'owner_changes_to_user_id' => array(
                        'type'        => array(
                            'integer',
                            'null',
                        ),
                        'description' => 'Saving makes you the task owner, as the Backups page does. Null when you already own it.',
                    ),
                ),
            ),
            self::get_backup_task_output_schema()
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_update_backup_task( $input ) {
        return self::execute_write( 'update-backup-task', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_delete_backup_task(): void {
        wp_register_ability(
            'mainwp/delete-backup-task-v1',
            array(
                'label'               => __( 'Delete Backup Task', 'mainwp' ),
                'description'         => __( 'Delete a task and, by default, its snapshots and their cloud copies (cloud copies only when the task deletes them). delete_snapshots:false keeps every snapshot. Refused while a run is open. dry_run lists the snapshots and cloud copies affected. Possible errors: mainwp_backup_already_running, mainwp_backup_task_not_found, mainwp_backup_legacy_disabled.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::delete_backup_task_input_schema(),
                'output_schema'       => self::delete_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/delete-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Destructive and not reversible. Requires confirm:true or dry_run:true. Call dry_run first and show the snapshot list to the user. Only call when the user explicitly requests deletion.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function delete_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id'          => self::task_id_schema(),
                'delete_snapshots' => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Also delete the task\'s snapshots, and their cloud copies when the task\'s delete_cloud_copies is on. false keeps every snapshot.',
                ),
                'dry_run'          => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'          => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function delete_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'task_name',
                    'delete_snapshots',
                    'delete_cloud_copies',
                    'snapshots',
                    'snapshots_truncated',
                    'cloud_copies',
                ),
                'properties'           => array(
                    'task_id'             => array(
                        'type' => 'integer',
                    ),
                    'task_name'           => array(
                        'type' => 'string',
                    ),
                    'delete_snapshots'    => array(
                        'type' => 'boolean',
                    ),
                    'delete_cloud_copies' => array(
                        'type' => 'boolean',
                    ),
                    'snapshots'           => array(
                        'type'     => 'array',
                        'maxItems' => 500,
                        'items'    => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'id',
                                'site_id',
                                'type',
                                'created_at',
                            ),
                            'properties'           => array(
                                'id'         => array(
                                    'type' => 'integer',
                                ),
                                'site_id'    => array(
                                    'type' => 'integer',
                                ),
                                'type'       => array(
                                    'type' => 'string',
                                    'enum' => array(
                                        'full',
                                        'db',
                                        'files',
                                        'incremental',
                                    ),
                                ),
                                'created_at' => self::ts_schema(),
                            ),
                        ),
                    ),
                    'snapshots_truncated' => array(
                        'type' => 'boolean',
                    ),
                    'cloud_copies'        => array(
                        'type'     => 'array',
                        'maxItems' => 500,
                        'items'    => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array(
                                'snapshot_id',
                                'destination_id',
                                'destination_name',
                            ),
                            'properties'           => array(
                                'snapshot_id'      => array(
                                    'type' => 'integer',
                                ),
                                'destination_id'   => array(
                                    'type' => 'integer',
                                ),
                                'destination_name' => array(
                                    'type'      => array(
                                        'string',
                                        'null',
                                    ),
                                    'maxLength' => 255,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'deleted',
                    'snapshots_deleted',
                ),
                'properties'           => array(
                    'task_id'           => array(
                        'type' => 'integer',
                    ),
                    'deleted'           => array(
                        'type' => 'boolean',
                        'enum' => array(
                            true,
                        ),
                    ),
                    'snapshots_deleted' => array(
                        'type' => 'boolean',
                    ),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_delete_backup_task( $input ) {
        return self::execute_write( 'delete-backup-task', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_pause_backup_task(): void {
        wp_register_ability(
            'mainwp/pause-backup-task-v1',
            array(
                'label'               => __( 'Pause Backup Task', 'mainwp' ),
                'description'         => __( 'Take a task off its schedule. A run that is already open stops being advanced until the task is resumed; nothing is cancelled on the sites. Possible errors: mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::pause_backup_task_input_schema(),
                'output_schema'       => self::pause_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_pause_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/pause-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function pause_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function pause_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'paused_now',
                    'next_run_at',
                ),
                'properties'           => array(
                    'task_id'     => array(
                        'type' => 'integer',
                    ),
                    'paused_now'  => array(
                        'type' => 'boolean',
                    ),
                    'next_run_at' => array_merge(
                        self::ts_schema(),
                        array(
                            'description' => 'Next run time after the action; null when the task would be paused.',
                        )
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'paused',
                    'next_run_at',
                ),
                'properties'           => array(
                    'task_id'     => array(
                        'type' => 'integer',
                    ),
                    'paused'      => array(
                        'type' => 'boolean',
                    ),
                    'next_run_at' => self::ts_schema(),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_pause_backup_task( $input ) {
        return self::execute_write( 'pause-backup-task', $input );
    }

    /** Discovery keeps consent and transport requirements alongside each action. */
    private static function register_resume_backup_task(): void {
        wp_register_ability(
            'mainwp/resume-backup-task-v1',
            array(
                'label'               => __( 'Resume Backup Task', 'mainwp' ),
                'description'         => __( 'Put a paused task back on its schedule, let an open run continue, and return the next run time. Possible errors: mainwp_backup_task_not_found, mainwp_backup_legacy_disabled.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::resume_backup_task_input_schema(),
                'output_schema'       => self::resume_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_resume_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/resume-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed input schemas prevent undocumented parameters from reaching an action.
     *
     * @return array
     */
    private static function resume_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'task_id',
            ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Required keys distinguish previews from confirmed results.
     *
     * @return array
     */
    private static function resume_backup_task_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'paused_now',
                    'next_run_at',
                ),
                'properties'           => array(
                    'task_id'     => array(
                        'type' => 'integer',
                    ),
                    'paused_now'  => array(
                        'type' => 'boolean',
                    ),
                    'next_run_at' => array_merge(
                        self::ts_schema(),
                        array(
                            'description' => 'Next run time after the action; null when the task would be paused.',
                        )
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array(
                    'task_id',
                    'paused',
                    'next_run_at',
                ),
                'properties'           => array(
                    'task_id'     => array(
                        'type' => 'integer',
                    ),
                    'paused'      => array(
                        'type' => 'boolean',
                    ),
                    'next_run_at' => self::ts_schema(),
                ),
            )
        );
    }

    /**
     * Callback authorization also protects direct PHP callers.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_resume_backup_task( $input ) {
        return self::execute_write( 'resume-backup-task', $input );
    }

    /**
     * Normalized outputs include null markers that submitted fields cannot use.
     *
     * @return array
     */
    private static function task_fields_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'name',
                'type',
                'schedule',
                'timing',
                'interval_hours',
                'time_of_day',
                'day_of_week',
                'day_of_month',
                'site_ids',
                'group_ids',
                'destination_ids',
                'retention',
                'archive_format',
                'exclude',
                'exclude_flags',
                'archive_tuning',
            ),
            'properties'           => array(
                'name'            => array(
                    'type'      => 'string',
                    'maxLength' => 255,
                ),
                'type'            => array(
                    'type' => 'string',
                    'enum' => array(
                        'full',
                        'db',
                        'files',
                        'incremental',
                    ),
                ),
                'schedule'        => array(
                    'type' => 'string',
                    'enum' => array(
                        'daily',
                        'weekly',
                        'monthly',
                        'interval',
                    ),
                ),
                'timing'          => array(
                    'type'        => 'string',
                    'enum'        => array(
                        'time_of_day',
                        'interval',
                        'elapsed',
                    ),
                    'description' => 'elapsed: a daily, weekly or monthly task with no time_of_day runs whenever its interval has elapsed and has no fixed next run time.',
                ),
                'interval_hours'  => array(
                    'type'        => array(
                        'integer',
                        'null',
                    ),
                    'minimum'     => 1,
                    'maximum'     => 8760,
                    'description' => 'Null unless schedule is interval.',
                ),
                'time_of_day'     => array(
                    'type'        => array(
                        'string',
                        'null',
                    ),
                    'pattern'     => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
                    'description' => 'Null for interval schedules and for elapsed timing.',
                ),
                'day_of_week'     => array(
                    'type'        => array(
                        'integer',
                        'null',
                    ),
                    'minimum'     => 0,
                    'maximum'     => 6,
                    'description' => 'Null unless schedule is weekly.',
                ),
                'day_of_month'    => array(
                    'type'        => array(
                        'integer',
                        'null',
                    ),
                    'minimum'     => 1,
                    'maximum'     => 28,
                    'description' => 'Null unless schedule is monthly.',
                ),
                'site_ids'        => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
                'group_ids'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
                'destination_ids' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
                'retention'       => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array(
                        'count',
                        'days',
                        'storage_mb',
                        'delete_cloud_copies',
                        'max_chain_length',
                    ),
                    'properties'           => array(
                        'count'               => array(
                            'type'    => 'integer',
                            'minimum' => 0,
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
                'archive_format'  => array(
                    'type' => 'string',
                    'enum' => array(
                        'site',
                        'global',
                        'zip',
                        'tar',
                        'tar.gz',
                        'tar.bz2',
                    ),
                ),
                'exclude'         => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'      => 'string',
                        'maxLength' => 255,
                    ),
                ),
                'exclude_flags'   => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array(
                        'backup_archives',
                        'cache',
                        'non_wp_folders',
                        'zip_archives',
                    ),
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
                'archive_tuning'  => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array(
                        'max_file_descriptors_override',
                        'max_file_descriptors_auto',
                        'max_file_descriptors',
                        'load_files_before_zip',
                        'zip_force_override',
                    ),
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
            ),
        );
    }

    /** Restore writes share the existing consent envelope and site authority. */
    private static function register_restore_site_snapshot(): void {
        wp_register_ability(
            'mainwp/restore-site-snapshot-v1',
            array(
                'label'               => __( 'Restore Site Snapshot', 'mainwp' ),
                'description'         => __( 'Restore a site from one of its snapshots. Full and database restores replace the site\'s database; content changed since the snapshot is lost. By default a full safety backup is taken first so rollback-restore-v1 can undo the restore. Returns a restore job id; cron runs the restore. Poll get-restore-job-v1 and read outcome, partial_state_risk and verification. dry_run is the consent step: snapshot age, what is replaced, WooCommerce warnings and the refusals known without contacting the site. Possible errors: mainwp_backup_legacy_disabled, mainwp_backup_site_disconnected, mainwp_backup_already_running, mainwp_backup_snapshot_not_found, mainwp_site_not_found, mainwp_restore_woo_legacy_orders, mainwp_restore_woo_sync_mode, mainwp_restore_woo_version_skew, mainwp_restore_child_update_required, mainwp_restore_schema_repair_pending, and the mainwp_backup_snapshot_unrestorable_* codes when the snapshot cannot be restored (dry_run shows the reason).', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::restore_site_snapshot_input_schema(),
                'output_schema'       => self::restore_site_snapshot_output_schema(),
                'execute_callback'    => array( self::class, 'execute_restore_site_snapshot' ),
                'permission_callback' => self::permission_callback( 'mainwp/restore-site-snapshot-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Destructive. Requires confirm:true or dry_run:true. Call dry_run first and show the user replaces_database, the snapshot age and the safety backup plan. Site capability and WooCommerce checks run only at confirm. The site does not roll back files on failure; rollback relies on the safety backup.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_restore_site_snapshot( $input ) {
        return self::execute_write( 'restore-site-snapshot', $input );
    }

    /** Restore writes share the existing consent envelope and site authority. */
    private static function register_cancel_restore(): void {
        wp_register_ability(
            'mainwp/cancel-restore-v1',
            array(
                'label'               => __( 'Cancel Restore', 'mainwp' ),
                'description'         => __( 'Request cancellation of a restore job that has not reached verification, or stop checking a completed restore when the Dashboard cannot reach the site. Stopping checks ends the job as failed and keeps rollback available where a safety backup exists. cancel_pending means the request is recorded but the job has not stopped; a request recorded before staging is applied when the job reaches staging. Poll get-restore-job-v1 until the stop is confirmed. Results: cancelled, cancel_pending, cancel_unconfirmed (the site never confirmed within 15 minutes and may be partly restored), ended_failed, or not_cancellable. Possible errors: mainwp_backup_restore_job_not_found, mainwp_operation_failed, mainwp_restore_schema_repair_pending.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::cancel_restore_input_schema(),
                'output_schema'       => self::cancel_restore_output_schema(),
                'execute_callback'    => array( self::class, 'execute_cancel_restore' ),
                'permission_callback' => self::permission_callback( 'mainwp/cancel-restore-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. After cancel_pending or cancel_unconfirmed read get-restore-job-v1 before reporting the site state.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_cancel_restore( $input ) {
        return self::execute_write( 'cancel-restore', $input );
    }

    /** Restore writes share the existing consent envelope and site authority. */
    private static function register_rollback_restore(): void {
        wp_register_ability(
            'mainwp/rollback-restore-v1',
            array(
                'label'               => __( 'Roll Back Restore', 'mainwp' ),
                'description'         => __( 'Restore the full safety backup taken before a restore job, undoing that restore. Starts a new restore job; poll get-restore-job-v1 with the returned restore_job_id. Available only when the source job took a safety backup and has finished (completed, failed or cancelled). Possible errors: mainwp_restore_rollback_status, mainwp_backup_site_disconnected, mainwp_backup_already_running, mainwp_backup_restore_job_not_found, mainwp_backup_legacy_disabled, mainwp_restore_schema_repair_pending.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::rollback_restore_input_schema(),
                'output_schema'       => self::rollback_restore_output_schema(),
                'execute_callback'    => array( self::class, 'execute_rollback_restore' ),
                'permission_callback' => self::permission_callback( 'mainwp/rollback-restore-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Destructive. Requires confirm:true or dry_run:true. Call dry_run first and show the user the source job and that the whole safety backup is applied.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_rollback_restore( $input ) {
        return self::execute_write( 'rollback-restore', $input );
    }

    /**
     * Restore consent and safety choices exclude internal options.
     *
     * @return array
     */
    private static function restore_site_snapshot_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id_or_domain', 'snapshot_id' ),
            'properties'           => array(
                'site_id_or_domain'  => self::site_id_or_domain_schema(),
                'snapshot_id'        => self::snapshot_id_schema(),
                'take_safety_backup' => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Take a full safety backup of the site first so the restore can be rolled back. The Snapshots page has this on by default.',
                ),
                'preserve_orders'    => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'WooCommerce: keep orders placed after the snapshot. Refused at confirm when the store cannot support it.',
                ),
                'dry_run'            => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'            => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Exact IDs prevent coercion from changing the cancellation target.
     *
     * @return array
     */
    private static function cancel_restore_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'restore_job_id' ),
            'properties'           => array(
                'restore_job_id' => self::restore_job_id_schema(),
                'dry_run'        => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'        => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Rollback names the recorded source job, never an artifact path.
     *
     * @return array
     */
    private static function rollback_restore_input_schema(): array {
        return self::cancel_restore_input_schema();
    }

    /**
     * The preview exposes consent facts without artifact paths.
     *
     * @return array
     */
    private static function restore_site_snapshot_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'site_id', 'snapshot', 'restorable', 'restore_reason', 'restore_type', 'replaces_database', 'chain_ids', 'site_has_woocommerce', 'safety_backup', 'preserve_orders' ),
                'properties'           => array(
                    'site_id'              => array( 'type' => 'integer' ),
                    'snapshot'             => self::snapshot_summary_schema(),
                    'restorable'           => array( 'type' => 'boolean' ),
                    'restore_reason'       => self::safe_error_or_null_schema(),
                    'restore_type'         => array(
                        'type' => 'string',
                        'enum' => array( 'full', 'db', 'files' ),
                    ),
                    'replaces_database'    => array(
                        'type'        => 'boolean',
                        'description' => 'True for full and db restores: content changed since the snapshot is lost.',
                    ),
                    'chain_ids'            => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                    ),
                    'site_has_woocommerce' => array( 'type' => 'boolean' ),
                    'safety_backup'        => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'will_take', 'type' ),
                        'properties'           => array(
                            'will_take' => array( 'type' => 'boolean' ),
                            'type'      => array(
                                'type' => 'string',
                                'enum' => array( 'full' ),
                            ),
                        ),
                    ),
                    'preserve_orders'      => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'requested', 'checked_at' ),
                        'properties'           => array(
                            'requested'  => array( 'type' => 'boolean' ),
                            'checked_at' => array(
                                'type' => 'string',
                                'enum' => array( 'confirm' ),
                            ),
                        ),
                    ),
                ),
            ),
            self::restore_start_schema( 'snapshot_id' )
        );
    }

    /**
     * A pending cancellation must never imply an immediate stop.
     *
     * @return array
     */
    private static function cancel_restore_output_schema(): array {
        $status = self::restore_job_summary_schema()['properties']['status'];
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'restore_job_id', 'status', 'cancellable', 'site_will_be_asked' ),
                'properties'           => array(
                    'restore_job_id'     => array( 'type' => 'integer' ),
                    'status'             => $status,
                    'cancellable'        => array( 'type' => 'boolean' ),
                    'site_will_be_asked' => array(
                        'type'        => 'boolean',
                        'description' => 'True while the site is receiving or applying the restore: the site must confirm it stopped, which can take up to 15 minutes.',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'restore_job_id', 'result', 'status' ),
                'properties'           => array(
                    'restore_job_id' => array( 'type' => 'integer' ),
                    'result'         => array(
                        'type'        => 'string',
                        'enum'        => array( 'cancelled', 'cancel_pending', 'cancel_unconfirmed', 'ended_failed', 'not_cancellable' ),
                        'description' => 'cancelled: the job stopped and the site confirmed, or never had a restore task. cancel_pending: the request is recorded but the job has not stopped; a request recorded before staging is applied when the job reaches staging. Poll get-restore-job-v1 until the stop is confirmed. cancel_unconfirmed: the site never confirmed it stopped within 15 minutes. ended_failed: the restore had already failed and the site confirmed the cancel, or checks were stopped after the restore finished on the site. Rollback stays available where a safety backup exists. not_cancellable: the job had already finished, is running its first verification check, or a verification retry is checking right now; in that last case call again once get-restore-job-v1 shows the retry waiting.',
                    ),
                    'status'         => $status,
                ),
            )
        );
    }

    /**
     * Rollback previews disclose only the source and its retained safety backup.
     *
     * @return array
     */
    private static function rollback_restore_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'source_restore_job_id', 'site_id', 'rollback_available', 'source_status', 'restore_type' ),
                'properties'           => array(
                    'source_restore_job_id' => array( 'type' => 'integer' ),
                    'site_id'               => array( 'type' => 'integer' ),
                    'rollback_available'    => array( 'type' => 'boolean' ),
                    'source_status'         => self::restore_job_summary_schema()['properties']['status'],
                    'restore_type'          => array(
                        'type'        => 'string',
                        'enum'        => array( 'full' ),
                        'description' => 'A rollback restores the full safety backup.',
                    ),
                ),
            ),
            self::restore_start_schema( 'source_restore_job_id' )
        );
    }

    /**
     * The poll input pins the admitted job even if a later restore starts on its site.
     *
     * @param string $source Snapshot or source job field.
     * @return array
     */
    private static function restore_start_schema( string $source ): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'restore_job_id', 'site_id', $source, 'status', 'started_at', 'poll' ),
            'properties'           => array(
                'restore_job_id' => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'site_id'        => array( 'type' => 'integer' ),
                $source          => array( 'type' => 'integer' ),
                'status'         => array(
                    'type' => 'string',
                    'enum' => array( 'started', 'not_started' ),
                ),
                'started_at'     => self::ts_schema(),
                'poll'           => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'ability', 'input', 'interval_seconds' ),
                    'properties'           => array(
                        'ability'          => array(
                            'type' => 'string',
                            'enum' => array( 'mainwp/get-restore-job-v1' ),
                        ),
                        'input'            => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'restore_job_id' ),
                            'properties'           => array( 'restore_job_id' => array( 'type' => 'integer' ) ),
                        ),
                        'interval_seconds' => array(
                            'type' => 'integer',
                            'enum' => array( 30 ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Required keys separate the branches even on WordPress versions that ignore const.
     *
     * @param array $preview Action-specific preview.
     * @param array $confirmed Confirmed result contract.
     * @return array
     */
    private static function write_output_schema( array $preview, array $confirmed ): array {
        $confirmed['required'][]            = 'dry_run';
        $confirmed['properties']['dry_run'] = array(
            'type'  => 'boolean',
            'const' => false,
        );
        return array(
            'oneOf' => array(
                array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'dry_run', 'would_affect', 'can_proceed', 'warnings', 'refusals' ),
                    'properties'           => array(
                        'dry_run'      => array(
                            'type'  => 'boolean',
                            'const' => true,
                        ),
                        'would_affect' => $preview,
                        'can_proceed'  => array(
                            'type'        => 'boolean',
                            'description' => 'False when confirm would be refused for a reason listed in refusals.',
                        ),
                        'warnings'     => array(
                            'type'     => 'array',
                            'maxItems' => 50,
                            'items'    => array(
                                'type'      => 'string',
                                'maxLength' => 500,
                            ),
                        ),
                        'refusals'     => array(
                            'type'     => 'array',
                            'maxItems' => 20,
                            'items'    => self::safe_error_schema(),
                        ),
                    ),
                ),
                $confirmed,
            ),
        );
    }

    /** Stored configuration writes must share consent and authorization with previews. */
    private static function register_test_backup_destination(): void {
        wp_register_ability(
            'mainwp/test-backup-destination-v1',
            array(
                'label'               => __( 'Test Backup Destination', 'mainwp' ),
                'description'         => __( 'Run the stored destination\'s connection test. Writes and deletes a probe object; for SFTP without a pinned host key it pins the key on first contact. Takes no settings: credentials stay in the Dashboard. Requires an account with access to all sites. Possible errors: mainwp_backup_destination_not_found, mainwp_backup_legacy_disabled, mainwp_restricted_account.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::test_backup_destination_input_schema(),
                'output_schema'       => self::test_backup_destination_output_schema(),
                'execute_callback'    => array( self::class, 'execute_test_backup_destination' ),
                'permission_callback' => self::permission_callback( 'mainwp/test-backup-destination-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true. The provider\'s own error text is never returned; the error code says which provider failed.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_test_backup_destination( $input ) {
        return self::execute_write( 'test-backup-destination', $input );
    }

    /** Stored configuration writes must share consent and authorization with previews. */
    private static function register_set_default_backup_destination(): void {
        wp_register_ability(
            'mainwp/set-default-backup-destination-v1',
            array(
                'label'               => __( 'Set Default Backup Destination', 'mainwp' ),
                'description'         => __( 'Make one destination the default. Back Up Now uploads to it for sites that no scheduled backup task includes, and new backup tasks on the Backups page start with it selected. Existing tasks keep their destinations. Requires an account with access to all sites. Possible errors: mainwp_backup_destination_not_found, mainwp_backup_legacy_disabled, mainwp_restricted_account.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::set_default_backup_destination_input_schema(),
                'output_schema'       => self::set_default_backup_destination_output_schema(),
                'execute_callback'    => array( self::class, 'execute_set_default_backup_destination' ),
                'permission_callback' => self::permission_callback( 'mainwp/set-default-backup-destination-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => __( 'Requires confirm:true or dry_run:true.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_set_default_backup_destination( $input ) {
        return self::execute_write( 'set-default-backup-destination', $input );
    }

    /** Stored configuration writes must share consent and authorization with previews. */
    private static function register_update_backup_destination(): void {
        wp_register_ability(
            'mainwp/update-backup-destination-v1',
            array(
                'label'               => __( 'Update Backup Destination', 'mainwp' ),
                'description'         => __( 'Rename a destination or change its default flag. Nothing else can be changed here: credentials, hosts, buckets and paths are edited on the Backup Storage page. The stored connection is tested before saving, and an SFTP destination without a pinned host key pins the key on first contact. Requires an account with access to all sites. Possible errors: mainwp_invalid_input, mainwp_backup_destination_not_found, mainwp_backup_destination_credentials_required, mainwp_backup_legacy_disabled, mainwp_restricted_account, and the provider\'s failure code when the connection test fails.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::update_backup_destination_input_schema(),
                'output_schema'       => self::update_backup_destination_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_backup_destination' ),
                'permission_callback' => self::permission_callback( 'mainwp/update-backup-destination-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                        'instructions' => __( 'Requires confirm:true or dry_run:true.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_update_backup_destination( $input ) {
        return self::execute_write( 'update-backup-destination', $input );
    }

    /** Stored configuration writes must share consent and authorization with previews. */
    private static function register_delete_backup_destination(): void {
        wp_register_ability(
            'mainwp/delete-backup-destination-v1',
            array(
                'label'               => __( 'Delete Backup Destination', 'mainwp' ),
                'description'         => __( 'Remove a destination from the Dashboard: tasks that used it are detached, and its pending uploads and pending cloud deletions are failed. Objects already stored at the destination are left in place. dry_run lists the tasks and jobs affected. Requires an account with access to all sites. Possible errors: mainwp_backup_destination_not_found, mainwp_backup_legacy_disabled, mainwp_restricted_account.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::delete_backup_destination_input_schema(),
                'output_schema'       => self::delete_backup_destination_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_backup_destination' ),
                'permission_callback' => self::permission_callback( 'mainwp/delete-backup-destination-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Destructive on the Dashboard side only. Requires confirm:true or dry_run:true. Call dry_run first and show the detached tasks to the user.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_delete_backup_destination( $input ) {
        return self::execute_write( 'delete-backup-destination', $input );
    }

    /** Stored configuration writes must share consent and authorization with previews. */
    private static function register_update_backup_settings(): void {
        wp_register_ability(
            'mainwp/update-backup-settings-v1',
            array(
                'label'               => __( 'Update Backup Settings', 'mainwp' ),
                'description'         => __( 'Change Built-In Backups on or off, the on-demand retention count, the concurrency limit, diagnostics, and the pre-update backup check policy. Encryption and the recovery passphrase cannot be changed here. Turning Built-In Backups off stops scheduled backups; turning them on clears a third-party primary method; dry_run reports both. Possible errors: mainwp_invalid_input, mainwp_restricted_account.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'ability_class'       => MainWP_Backup_Ability::class,
                'input_schema'        => self::update_backup_settings_input_schema(),
                'output_schema'       => self::update_backup_settings_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_backup_settings' ),
                'permission_callback' => self::permission_callback( 'mainwp/update-backup-settings-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                        'instructions' => __( 'Changes Dashboard-wide behavior. Requires confirm:true or dry_run:true. Call dry_run first and show the changes to the user.', 'mainwp' ),
                    ),
                ),
            )
        );
    }

    /**
     * Direct callbacks repeat authorization before preview or dispatch.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_update_backup_settings( $input ) {
        return self::execute_write( 'update-backup-settings', $input );
    }

    /**
     * Stored tests, defaults and deletes need only an identity and consent.
     *
     * @return array
     */
    private static function test_backup_destination_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'destination_id' ),
            'properties'           => array(
                'destination_id' => self::destination_id_schema(),
                'dry_run'        => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Preview only. Changes nothing and contacts no site.',
                ),
                'confirm'        => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
                ),
            ),
        );
    }

    /**
     * Identical consent schemas keep stored destination actions consistent.
     *
     * @return array
     */
    private static function set_default_backup_destination_input_schema(): array {
        return self::test_backup_destination_input_schema();
    }

    /**
     * Identical consent schemas keep stored destination actions consistent.
     *
     * @return array
     */
    private static function delete_backup_destination_input_schema(): array {
        return self::test_backup_destination_input_schema();
    }

    /**
     * Connection fields stay on the Dashboard's storage form.
     *
     * @return array
     */
    private static function update_backup_destination_input_schema(): array {
        // Tags and whitespace can shrink the name; the service bounds its sanitized value.
        $schema                             = self::test_backup_destination_input_schema();
        $schema['properties']['name']       = array( 'type' => 'string' );
        $schema['properties']['is_default'] = array( 'type' => 'boolean' );
        return $schema;
    }

    /**
     * Machine settings exclude encryption and recovery material.
     *
     * @return array
     */
    private static function update_backup_settings_input_schema(): array {
        $schema = self::get_backup_settings_output_schema();
        unset( $schema['required'], $schema['properties']['encryption'], $schema['properties']['primary_backup_method'] );
        $schema['properties']['on_demand_retention_count']['maximum'] = 100;
        $schema['properties']['max_concurrent_backups']['maximum']    = 10;
        unset( $schema['properties']['on_demand_retention_count']['description'], $schema['properties']['max_concurrent_backups']['description'], $schema['properties']['update_gate']['required'] );
        $schema['properties']['update_gate']['properties']['max_age_days']['maximum'] = 365;
        $envelope = self::test_backup_destination_input_schema();
        foreach ( array( 'dry_run', 'confirm' ) as $key ) {
            $schema['properties'][ $key ] = $envelope['properties'][ $key ];
        }
        return $schema;
    }

    /**
     * Closed result branches exclude internal configuration and provider text.
     *
     * @return array
     */
    private static function test_backup_destination_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'provider', 'writes_probe_object', 'may_pin_host_key' ),
                'properties'           => array(
                    'destination_id'      => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'provider'            => array(
                        'type' => 'string',
                        'enum' => array( 's3', 'gcs', 'dropbox', 'sftp', 'local' ),
                    ),
                    'writes_probe_object' => array(
                        'type' => 'boolean',
                    ),
                    'may_pin_host_key'    => array(
                        'type' => 'boolean',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'ok', 'error', 'host_key_pinned' ),
                'properties'           => array(
                    'destination_id'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'ok'              => array(
                        'type' => 'boolean',
                    ),
                    'error'           => self::safe_error_or_null_schema(),
                    'host_key_pinned' => array(
                        'type' => 'boolean',
                    ),
                ),
            )
        );
    }

    /**
     * Closed result branches exclude internal configuration and provider text.
     *
     * @return array
     */
    private static function set_default_backup_destination_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'previous_default_id' ),
                'properties'           => array(
                    'destination_id'      => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'previous_default_id' => array(
                        'type'    => array( 'integer', 'null' ),
                        'minimum' => 1,
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'is_default' ),
                'properties'           => array(
                    'destination_id' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'is_default'     => array(
                        'type' => 'boolean',
                        'enum' => array( true ),
                    ),
                ),
            )
        );
    }

    /**
     * Closed result branches exclude internal configuration and provider text.
     *
     * @return array
     */
    private static function update_backup_destination_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'changes', 'connection_test_runs', 'may_pin_host_key' ),
                'properties'           => array(
                    'destination_id'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'changes'              => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'field', 'from', 'to' ),
                            'properties'           => array(
                                'field' => array(
                                    'type' => 'string',
                                    'enum' => array( 'name', 'is_default' ),
                                ),
                                'from'  => array(
                                    'type' => array( 'string', 'boolean' ),
                                ),
                                'to'    => array(
                                    'type' => array( 'string', 'boolean' ),
                                ),
                            ),
                        ),
                    ),
                    'connection_test_runs' => array(
                        'type' => 'boolean',
                        'enum' => array( true ),
                    ),
                    'may_pin_host_key'     => array(
                        'type' => 'boolean',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination' ),
                'properties'           => array(
                    'destination' => self::destination_schema(),
                ),
            )
        );
    }

    /**
     * Closed result branches exclude internal configuration and provider text.
     *
     * @return array
     */
    private static function delete_backup_destination_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'tasks_detached', 'pending_jobs_failed', 'remote_objects_left_in_place', 'was_default' ),
                'properties'           => array(
                    'destination_id'               => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'tasks_detached'               => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'task_id', 'name' ),
                            'properties'           => array(
                                'task_id' => array(
                                    'type'    => 'integer',
                                    'minimum' => 1,
                                ),
                                'name'    => array(
                                    'type' => 'string',
                                ),
                            ),
                        ),
                    ),
                    'pending_jobs_failed'          => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'upload', 'delete' ),
                        'properties'           => array(
                            'upload' => array(
                                'type'    => 'integer',
                                'minimum' => 0,
                            ),
                            'delete' => array(
                                'type'    => 'integer',
                                'minimum' => 0,
                            ),
                        ),
                    ),
                    'remote_objects_left_in_place' => array(
                        'type' => 'boolean',
                        'enum' => array( true ),
                    ),
                    'was_default'                  => array(
                        'type' => 'boolean',
                    ),
                ),
            ),
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'destination_id', 'deleted', 'tasks_detached' ),
                'properties'           => array(
                    'destination_id' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'deleted'        => array(
                        'type' => 'boolean',
                        'enum' => array( true ),
                    ),
                    'tasks_detached' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                    ),
                ),
            )
        );
    }

    /**
     * Closed result branches exclude internal configuration and provider text.
     *
     * @return array
     */
    private static function update_backup_settings_output_schema(): array {
        return self::write_output_schema(
            array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array( 'changes', 'stops_scheduled_backups', 'clears_primary_backup_method', 'primary_backup_method' ),
                'properties'           => array(
                    'changes'                      => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'setting', 'from', 'to' ),
                            'properties'           => array(
                                'setting' => array(
                                    'type' => 'string',
                                    'enum' => array( 'native_backups_enabled', 'on_demand_retention_count', 'max_concurrent_backups', 'diagnostics_enabled', 'update_gate.enabled', 'update_gate.max_age_days', 'update_gate.require_offsite' ),
                                ),
                                'from'    => array(
                                    'type' => array( 'boolean', 'integer' ),
                                ),
                                'to'      => array(
                                    'type' => array( 'boolean', 'integer' ),
                                ),
                            ),
                        ),
                    ),
                    'stops_scheduled_backups'      => array(
                        'type' => 'boolean',
                    ),
                    'clears_primary_backup_method' => array(
                        'type' => 'boolean',
                    ),
                    'primary_backup_method'        => array(
                        'type'      => array( 'string', 'null' ),
                        'maxLength' => 64,
                    ),
                ),
            ),
            self::get_backup_settings_output_schema()
        );
    }

    /**
     * The service owns actions and previews; projection excludes internal handles and paths.
     *
     * @param string $name Action slug.
     * @param mixed  $input Caller input.
     * @return array|\WP_Error
     */
    private static function execute_write( string $name, $input ) {
        try {
            $allowed = self::authorize( 'mainwp/' . $name . '-v1', $input );
            if ( is_wp_error( $allowed ) ) {
                return $allowed;
            }
            $schema_method = str_replace( '-', '_', $name ) . '_input_schema';
            $schema        = self::$schema_method();
            if ( is_wp_error( rest_validate_value_from_schema( $input, $schema ) ) ) {
                return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
            }
            $input   = rest_sanitize_value_from_schema( $input, $schema );
            $input   = MainWP_Abilities_Util::normalize_input( $input );
            $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
            $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
            if ( $confirm && $dry_run ) {
                return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
            }
            if ( ! $confirm && ! $dry_run ) {
                return self::safe_wp_error( new \WP_Error( 'mainwp_confirmation_required', '', array( 'status' => 400 ) ) );
            }
            $stop = in_array( $name, array( 'cancel-site-backup', 'reset-backup-task-run', 'pause-backup-task', 'cancel-restore' ), true );
            if ( ! $stop && 'update-backup-settings' !== $name && ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
                return self::safe_wp_error( new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, '', array( 'status' => 409 ) ) );
            }
            $site_id         = isset( $input['site_id_or_domain'] ) ? (int) MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] )->id : 0;
            $task_id         = isset( $input['task_id'] ) ? self::positive_int( $input['task_id'] ) : 0;
            $snapshot_id     = isset( $input['snapshot_id'] ) ? self::positive_int( $input['snapshot_id'] ) : 0;
            $restore_job_id  = isset( $input['restore_job_id'] ) ? self::positive_int( $input['restore_job_id'] ) : 0;
            $destination_id  = isset( $input['destination_id'] ) ? self::positive_int( $input['destination_id'] ) : 0;
            $restore_options = array(
                'pre_snapshot'        => $input['take_safety_backup'] ?? true,
                'file_rollback'       => $input['take_safety_backup'] ?? true,
                'preserve_orders'     => $input['preserve_orders'] ?? false,
                'confirm_destructive' => $confirm,
            );
            $keep            = $input['keep'] ?? null;
            $fields          = array();
            if ( in_array( $name, array( 'create-backup-task', 'update-backup-task' ), true ) ) {
                $fields = self::task_write_fields( $input );
                if ( is_wp_error( $fields ) ) {
                    return self::safe_wp_error( $fields );
                }
            }
            if ( in_array( $name, array( 'update-backup-destination', 'update-backup-settings' ), true ) ) {
                $fields = $input;
                unset( $fields['destination_id'], $fields['dry_run'], $fields['confirm'] );
            }
            if ( $dry_run ) {
                switch ( $name ) {
                    case 'test-backup-destination':
                    case 'set-default-backup-destination':
                    case 'update-backup-destination':
                    case 'delete-backup-destination':
                        $result = MainWP_Backup_Operations::preview_destination( $destination_id, $name, $fields );
                        break;
                    case 'update-backup-settings':
                        $result = MainWP_Backup_Operations::preview_backup_settings( $fields );
                        break;
                    case 'delete-backup-snapshot':
                        $result = MainWP_Backup_Operations::preview_delete_snapshot( $site_id, $snapshot_id, $input['delete_cloud_copies'] ?? null );
                        break;
                    case 'restore-site-snapshot':
                        $result = MainWP_Backup_Operations::preview_restore_snapshot( $site_id, $snapshot_id, $restore_options );
                        break;
                    case 'cancel-restore':
                        $result = MainWP_Backup_Operations::preview_restore_cancel( $restore_job_id );
                        break;
                    case 'rollback-restore':
                        $result = MainWP_Backup_Operations::preview_restore_rollback( $restore_job_id );
                        break;
                    case 'create-backup-task':
                        $result = MainWP_Backup_Operations::preview_task_create( $fields );
                        break;
                    case 'update-backup-task':
                        $result = MainWP_Backup_Operations::preview_task_update( $task_id, $fields );
                        break;
                    case 'delete-backup-task':
                        $result = MainWP_Backup_Operations::preview_task_delete( $task_id, $input['delete_snapshots'] ?? true );
                        break;
                    case 'pause-backup-task':
                    case 'resume-backup-task':
                        $result = MainWP_Backup_Operations::preview_task_pause( $task_id, 'pause-backup-task' === $name );
                        break;
                    case 'run-site-backup':
                        $result = MainWP_Backup_Operations::preview_site_backup( $site_id );
                        break;
                    case 'run-backup-task':
                        $result = MainWP_Backup_Operations::preview_task_run( $task_id );
                        break;
                    case 'cancel-site-backup':
                        $result = MainWP_Backup_Operations::preview_site_cancel( $site_id );
                        break;
                    case 'reset-backup-task-run':
                        $result = MainWP_Backup_Operations::preview_task_reset( $task_id );
                        break;
                    case 'cleanup-site-backups':
                        $result = MainWP_Backup_Operations::preview_site_cleanup( $site_id, $keep );
                        break;
                }
            } else {
                switch ( $name ) {
                    case 'test-backup-destination':
                        $result = MainWP_Backup_Operations::test_destination( $destination_id );
                        if ( is_wp_error( $result ) && 502 === ( $result->get_error_data()['status'] ?? 0 ) ) {
                            $result = array(
                                'ok'              => false,
                                'error'           => MainWP_Backup_Operations::safe_error( $result->get_error_code() ),
                                'host_key_pinned' => ! empty( $result->get_error_data()['host_key_pinned'] ),
                            );
                        }
                        if ( ! is_wp_error( $result ) ) {
                            $result['destination_id'] = $destination_id;
                            $result['error']          = $result['error'] ?? null;
                        }
                        break;
                    case 'set-default-backup-destination':
                        $result = MainWP_Backup_Operations::set_default_destination( $destination_id );
                        if ( ! is_wp_error( $result ) ) {
                            $result = array(
                                'destination_id' => $destination_id,
                                'is_default'     => true,
                            );
                        }
                        break;
                    case 'update-backup-destination':
                        $result = MainWP_Backup_Operations::update_destination( $destination_id, $fields );
                        if ( ! is_wp_error( $result ) ) {
                            $result = array( 'destination' => MainWP_Backup_Read_Model::get_destination( $destination_id ) );
                        }
                        break;
                    case 'delete-backup-destination':
                        $result = MainWP_Backup_Operations::delete_destination( $destination_id );
                        if ( ! is_wp_error( $result ) ) {
                            $result['destination_id'] = $destination_id;
                        }
                        break;
                    case 'update-backup-settings':
                        $result = MainWP_Backup_Operations::save_backup_settings( $fields, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $result = MainWP_Backup_Read_Model::get_settings();
                        }
                        break;
                    case 'delete-backup-snapshot':
                        $result = MainWP_Backup_Operations::delete_snapshot( $site_id, $snapshot_id, $input['expected_snapshot_ids'] ?? array(), $input['delete_cloud_copies'] ?? null );
                        break;
                    case 'restore-site-snapshot':
                    case 'rollback-restore':
                        $source_job = 'rollback-restore' === $name ? MainWP_Backup_Operations::authorize_restore_job( $restore_job_id ) : null;
                        if ( is_wp_error( $source_job ) ) {
                            return self::safe_wp_error( $source_job );
                        }
                        $result = 'restore-site-snapshot' === $name
                            ? MainWP_Backup_Operations::restore_snapshot( $site_id, $snapshot_id, $restore_options, 'ability' )
                            : MainWP_Backup_Operations::rollback_restore( $restore_job_id, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            // The atomic promotion admits this job; a fresh transport read supplies its time.
                            $admitted_job             = MainWP_Backup_Restore_Job_Store::instance()->get_job( $result['job_id'] );
                            $admitted                 = null !== $admitted_job && 'reserved' !== $admitted_job->status;
                            $result['status']         = $admitted ? 'started' : 'not_started';
                            $result['started_at']     = $admitted ? (int) $admitted_job->created : null;
                            $result['restore_job_id'] = $result['job_id'];
                            $result['site_id']        = $source_job ? (int) $source_job->site_id : $site_id;
                            $result[ $source_job ? 'source_restore_job_id' : 'snapshot_id' ] = $source_job ? $restore_job_id : $snapshot_id;
                            $result['poll'] = array(
                                'ability'          => 'mainwp/get-restore-job-v1',
                                'input'            => array( 'restore_job_id' => $result['restore_job_id'] ),
                                'interval_seconds' => 30,
                            );
                        }
                        break;
                    case 'cancel-restore':
                        $result = MainWP_Backup_Operations::cancel_restore( $restore_job_id, 'ability' );
                        break;
                    case 'create-backup-task':
                    case 'update-backup-task':
                        $result = 'create-backup-task' === $name ? MainWP_Backup_Operations::create_task( $fields, 'ability' ) : MainWP_Backup_Operations::update_task( $task_id, $fields, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $result = MainWP_Backup_Read_Model::get_task( MainWP_DB_Backup::instance()->get_backup_task_by_id( $result['task_id'] ) );
                        }
                        break;
                    case 'delete-backup-task':
                        $delete_snapshots = $input['delete_snapshots'] ?? true;
                        $result           = MainWP_Backup_Operations::delete_task( $task_id, $delete_snapshots, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $result = array(
                                'task_id'           => $task_id,
                                'deleted'           => true,
                                'snapshots_deleted' => $delete_snapshots,
                            );
                        }
                        break;
                    case 'pause-backup-task':
                    case 'resume-backup-task':
                        $paused = 'pause-backup-task' === $name;
                        $result = $paused ? MainWP_Backup_Operations::pause_task( $task_id, 'ability' ) : MainWP_Backup_Operations::resume_task( $task_id, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
                            $result = array(
                                'task_id'     => $task_id,
                                'paused'      => ! empty( $task->paused ),
                                'next_run_at' => $paused ? null : (int) $task->next_run,
                            );
                        }
                        break;
                    case 'run-site-backup':
                        $result = MainWP_Backup_Operations::start_site_backup( $site_id, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $since  = (int) $result['started'];
                            $result = array(
                                'site_id'    => $site_id,
                                'status'     => $result['status'],
                                'started_at' => 'not_started' === $result['status'] ? null : $since,
                                'since'      => $since,
                                'poll'       => array(
                                    'ability'          => 'mainwp/get-site-backup-progress-v1',
                                    'input'            => array(
                                        'site_id_or_domain' => $site_id,
                                        'since' => $since,
                                    ),
                                    'interval_seconds' => 30,
                                ),
                            );
                        }
                        break;
                    case 'run-backup-task':
                        $result = MainWP_Backup_Operations::run_task( $task_id, 'ability' );
                        if ( ! is_wp_error( $result ) ) {
                            $result = array(
                                'task_id'    => $task_id,
                                'status'     => $result['status'],
                                'started_at' => 'not_started' === $result['status'] ? null : (int) $result['started'],
                                'poll'       => array(
                                    'ability'          => 'mainwp/get-backup-task-run-v1',
                                    'input'            => array( 'task_id' => $task_id ),
                                    'interval_seconds' => 60,
                                ),
                            );
                        }
                        break;
                    case 'cancel-site-backup':
                        $result = MainWP_Backup_Operations::cancel_site_backup( $site_id );
                        if ( ! is_wp_error( $result ) ) {
                            $messages          = self::cancel_messages();
                            $result['site_id'] = $site_id;
                            $result['message'] = $messages[ $result['result'] ];
                        }
                        break;
                    case 'reset-backup-task-run':
                        $result = MainWP_Backup_Operations::reset_task_run( $task_id );
                        if ( ! is_wp_error( $result ) ) {
                            $result = array(
                                'task_id'           => $task_id,
                                'status'            => 'reset',
                                'sites_cancelled'   => $result['cancelled'],
                                'sites_uncancelled' => $result['uncancelled'],
                            );
                        }
                        break;
                    case 'cleanup-site-backups':
                        $result = MainWP_Backup_Operations::cleanup_site_backups( $site_id, $keep );
                        if ( ! is_wp_error( $result ) ) {
                            $result['site_id']      = $site_id;
                            $result['units_pruned'] = $result['pruned'];
                            $result['errors']       = array_map(
                                static function ( $error ) {
                                    return self::cleanup_error( $error );
                                },
                                $result['errors']
                            );
                        }
                        break;
                }
            }
            if ( is_wp_error( $result ) ) {
                return self::safe_wp_error( $result );
            }
            $result['dry_run'] = $dry_run;
            $schema_method     = str_replace( '-', '_', $name ) . '_output_schema';
            $schema            = self::$schema_method();
            $output            = self::serialize( $result, $schema['oneOf'][ $dry_run ? 0 : 1 ] );
            if ( in_array( $name, array( 'cleanup-site-backups', 'delete-backup-snapshot' ), true ) && ! $dry_run ) {
                // These errors have already been sanitized by cleanup_error().
                $output['errors'] = $result['errors'];
            }
            if ( 'delete-backup-snapshot' === $name ) {
                // Operations supplies the specific hold message for both transports.
                if ( $dry_run ) {
                    $output['refusals'] = $result['refusals'];
                } elseif ( null !== $result['preview'] ) {
                    $output['preview']['refusals'] = $result['preview']['refusals'];
                }
            }
            return $output;
        } catch ( \Throwable $exception ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_internal_error', '', array( 'status' => 500 ) ) );
        }
    }

    /**
     * Only submitted fields reach the partial-update service; omissions keep stored values.
     *
     * @param array $input Validated ability input.
     * @return array|\WP_Error
     */
    private static function task_write_fields( array $input ) {
        unset( $input['task_id'], $input['dry_run'], $input['confirm'] );
        if ( array_key_exists( 'site_ids_or_domains', $input ) ) {
            $sites = array();
            foreach ( $input['site_ids_or_domains'] as $id ) {
                $site = MainWP_Abilities_Util::resolve_site( $id );
                if ( is_wp_error( $site ) ) {
                    return $site;
                }
                $sites[] = (int) $site->id;
            }
            unset( $input['site_ids_or_domains'] );
            $input['site_ids'] = $sites;
        }
        foreach ( array( 'group_ids', 'destination_ids' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $input[ $key ] = array_map( array( self::class, 'positive_int' ), $input[ $key ] );
            }
        }
        return $input;
    }

    /**
     * Fixed messages exclude untrusted Child response text.
     *
     * @return array
     */
    private static function cancel_messages(): array {
        return array(
            'cancelled'         => __( 'The site confirmed that it stopped the backup.', 'mainwp' ),
            'already_finished'  => __( 'The site had already finished the backup. The Dashboard will still collect and record it.', 'mainwp' ),
            'already_failed'    => __( 'The site had already failed the backup. The Dashboard will record the failure.', 'mainwp' ),
            'not_running'       => __( 'No on-demand backup is open on the Dashboard for this site.', 'mainwp' ),
            'no_active_on_site' => __( 'The site reports no matching backup. The Dashboard run remains open until it is reset after the stall threshold.', 'mainwp' ),
            'busy'              => __( 'The backup could not be stopped yet. Read its progress and try cancelling again.', 'mainwp' ),
        );
    }
}
