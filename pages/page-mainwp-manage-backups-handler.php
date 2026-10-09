<?php
/**
 * MainWP Legacy Backups Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Manage_Backups_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Backups_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Site option holding a child cancel the child refused, owed to a later pass.
     *
     * @var string
     */
    const PENDING_CANCEL_OPTION = 'backup_pending_child_cancel';

    /**
     * How many passes may retry a refused child cancel before giving up.
     *
     * @var int
     */
    const PENDING_CANCEL_MAX_ATTEMPTS = 10;

    /**
     * Backups a new scheduled task keeps per site when the request does not
     * say. Applied in code, not as the column default, so existing tasks keep
     * the value they were saved with.
     *
     * @var int
     */
    const NEW_TASK_RETENTION_COUNT = 7;

    /**
     * Instance variable.
     *
     * @var null $instance.
     */
    private static $instance = null;

    /**
     * Create instance.
     *
     * @return self $instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Validate Backup Tasks.
     *
     * @param array $pBackupTasks Backup tasks.
     *
     * @return bool true|false.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     */
    public static function validate_backup_tasks( $pBackupTasks ) {  //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $pBackupTasks ) ) {
            return true;
        }

        $nothingChanged = true;
        foreach ( $pBackupTasks as $backupTask ) {
            if ( '' === $backupTask->groups ) {
                $newSiteIds = '';
                $siteIds    = ( '' === $backupTask->sites ? array() : explode( ',', $backupTask->sites ) );
                foreach ( $siteIds as $siteId ) {
                    $site = MainWP_DB::instance()->get_website_by_id( $siteId );
                    if ( ! empty( $site ) ) {
                        $newSiteIds .= ',' . $siteId;
                    }
                }

                $newSiteIds = trim( $newSiteIds, ',' );

                if ( $newSiteIds !== $backupTask->sites ) {
                    $nothingChanged = false;
                    MainWP_DB_Backup::instance()->update_backup_task_with_values( $backupTask->id, array( 'sites' => $newSiteIds ) );
                }
            } else {
                $newGroupIds = '';
                $groupIds    = explode( ',', $backupTask->groups );
                foreach ( $groupIds as $groupId ) {
                    $group = MainWP_DB_Common::instance()->get_group_by_id( $groupId );
                    if ( ! empty( $group ) ) {
                        $newGroupIds .= ',' . $groupId;
                    }
                }
                $newGroupIds = trim( $newGroupIds, ',' );

                if ( $newGroupIds !== $backupTask->groups ) {
                    $nothingChanged = false;
                    MainWP_DB_Backup::instance()->update_backup_task_with_values( $backupTask->id, array( 'groups' => $newGroupIds ) );
                }
            }
        }

        return $nothingChanged;
    }

    /**
     * Start or follow a manual run of a saved backup task.
     *
     * Identity movement past the baseline permits adoption; at the baseline, only
     * the operator's own manual close inside the settle window permits debounce.
     * Rows correspond to the manual_run_ownership_cases test provider.
     *
     * | Row | Entry and inputs | Expected outcome |
     * | --- | --- | --- |
     * | 1 | Poll, no run or click | Failed, unowned |
     * | 2 | Poll owns an open run | Continue it without claiming |
     * | 3 | Poll owns superseded run, no saved copy | run_not_found |
     * | 4 | Poll owns a clean close | Completed with its snapshot |
     * | 5 | Poll, closed identity beyond baseline, no errors, snapshot | Adopt, completed |
     * | 6 | Poll, closed identity equals baseline, settle expired | Claim, running |
     * | 7 | Poll, closed run has errors | Claim again, running |
     * | 8 | Initiate, slot refused, identity unchanged | Queued with baseline |
     * | 9 | Either entry, paused task | Refuse |
     * | 10 | Initiate, same-second scheduled clean close before click, slot refused | Queued, never adopt baseline |
     * | 11 | Poll, identity beyond baseline still open | Queued; adopt only after clean close with snapshot |
     * | 12 | Initiate, claim refused after foreign open | Queued, no manual stamp |
     * | 13 | Poll owns superseded clean run, saved copy | Its saved snapshot |
     * | 14 | Poll owns superseded failed run, saved copy | Its saved errors |
     * | 15 | Initiate, recent manual close at baseline, claim refused | Adopt, completed |
     * | 16 | Either entry, site not editable | Refuse |
     * | 17 | Initiate, run already open | Running, started = last_run, no adoption |
     * | 18 | Initiate, claim succeeds | Running; next poll completes |
     * | 19 | Poll, manual close at baseline within settle | Adopt, completed |
     * | 20 | Either entry, task not editable | Refuse |
     * | 21 | Poll, identity beyond baseline closes with errors | Claim again, never adopt |
     * | 22 | Poll owns superseded scheduled run, saved copy | Its saved snapshot |
     * | 23 | Either entry, task missing | Refuse |
     * | 24 | Either entry, native backups disabled | Refuse |
     * | 25 | Either entry, zero sites with stale ids | Stale-sites error; scheduled run records it |
     * | 26 | Either entry, empty group without stale ids | Edit-and-select-sites error; scheduled run records it |
     * | 27 | Either entry, restore in progress | Refuse |
     * | 28 | Poll, no baseline in old handle | Claim again, never adopt |
     * | 29 | Initiate, refused claim after clean close beyond baseline | Adopt with snapshot |
     * | 30 | Poll, clean close beyond baseline without snapshot | Claim again, never adopt |
     * | 31 | Initiate, claim and close within call | Running; manual stamp precedes first site call |
     * | 32 | Poll during close before error flush, no snapshot | Queued, never adopt |
     * | 33 | Poll, clean close beyond baseline, only stale snapshot | Claim again, never adopt |
     * | 34 | Poll, scheduled clean close at baseline within settle | Queued, never adopt |
     * | 35 | Poll, manual close at baseline older than settle | Claim again, running |
     *
     * @param int $task_id Task id.
     * @return array Job handle or error.
     */
    public static function run_backup_task_now( int $task_id ): array {
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( empty( $task ) || ! static::can_edit_backuptask( $task ) ) {
            return array( 'error' => esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) );
        }
        $stale_sites = array();
        $error       = static::manual_task_start_error( $task, $stale_sites );
        if ( '' !== $error ) {
            return array( 'error' => $error );
        }
        MainWP_Post_Backup_Handler::raise_backup_resource_limits();
        if ( (int) $task->completed < (int) $task->last_run ) {
            return array(
                'job_id'      => $task_id,
                'started'     => (int) $task->last_run,
                'click'       => 0,
                'status'      => 'running',
                'stale_sites' => $stale_sites,
            );
        }

        $click         = time();
        $baseline      = (int) $task->last_run;
        $initiation_id = wp_generate_uuid4();
        $baseline_key  = 'mainwp_backup_click_' . $task_id . '_' . get_current_user_id() . '_' . $initiation_id;
        set_transient(
            $baseline_key,
            array(
                'baseline' => $baseline,
                'click'    => $click,
            ),
            30 * MINUTE_IN_SECONDS
        );
        $run                  = static::claim_manual_task_run( $task, $click, $baseline );
        $run['initiation_id'] = $initiation_id;
        if ( 'queued' !== ( $run['status'] ?? '' ) ) {
            delete_transient( $baseline_key );
        }
        MainWP_Post_Backup_Handler::kick_backup_continuation();
        return isset( $run['error'] ) ? $run : array_merge(
            array(
                'job_id'      => $task_id,
                'stale_sites' => $stale_sites,
            ),
            $run
        );
    }

    /**
     * Resolve a manual claim without confusing a slot refusal with a clean close.
     *
     * @param object   $task  Task row, refreshed after the attempt.
     * @param int      $click Original click timestamp.
     * @param int|null $baseline Run identity before the click; null disables adoption.
     * @param float    $deadline Shared status request deadline; zero disables the budget.
     * @param bool     $driven Whether this request has already visited a site.
     * @return array Run identity and status, or error.
     */
    private static function claim_manual_task_run( object &$task, int $click, ?int $baseline = null, float $deadline = 0.0, bool &$driven = false ): array {
        $task_id = (int) $task->id;
        $claimed = null;
        $started = 0;
        $settle  = MainWP_Backup_Settings::get_manual_run_settle_seconds();
        $outcome = null;
        static::execute_backup_task( $task, 5, true, max( 1, $settle ), $claimed, $started, true, $outcome, $deadline );
        $driven = $driven || $outcome['sites_visited'] > 0;
        $task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( empty( $task ) ) {
            return array( 'error' => esc_html__( 'The backup task could not be started. Reload the page and try again.', 'mainwp' ) );
        }
        if ( true === $claimed ) {
            return array(
                'started' => $started,
                'click'   => 0,
                'status'  => 'running',
            );
        }
        if ( false === $claimed && static::can_adopt_closed_task_run( $task, $baseline, $click ) ) {
            return static::get_backup_task_run_status( $task_id, (int) $task->last_run, $click, false, $baseline, $deadline > 0.0 ? $deadline : null, $driven );
        }
        return array(
            'started'  => 0,
            'click'    => $click,
            'status'   => 'queued',
            'baseline' => $baseline,
            'run_open' => (int) $task->completed < (int) $task->last_run,
        );
    }

    /**
     * A manual stamp permits debounce of the same recent run; scheduled closes
     * require identity movement past the baseline.
     *
     * @param object   $task Task row.
     * @param int|null $baseline Run identity before the click; null disables adoption.
     * @param int      $click Original click timestamp.
     * @return bool Whether this closed run can satisfy the queued click.
     */
    private static function can_adopt_closed_task_run( object $task, ?int $baseline, int $click ): bool {
        if ( null === $baseline || (int) $task->last_run < $baseline || (int) $task->completed < (int) $task->last_run || '' !== (string) $task->backup_errors ) {
            return false;
        }
        if ( (int) $task->last_run === $baseline && ( (int) $task->last_run_manually !== (int) $task->last_run || (int) $task->completed < $click - MainWP_Backup_Settings::get_manual_run_settle_seconds() ) ) {
            return false;
        }
        $sites = static::resolve_task_run_sites( $task );
        if ( empty( $sites ) ) {
            return false;
        }
        foreach ( $sites as $site_id ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id );
            if ( ! $snapshot || (int) $snapshot->created < (int) $task->last_run ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check guards shared by initial and queued manual claims.
     *
     * @param object $task Task row.
     * @param array  $stale_sites Deleted site ids omitted from the run.
     * @return string Refusal message, or an empty string.
     */
    private static function manual_task_start_error( object $task, array &$stale_sites = array() ): string {
        if ( ! empty( $task->paused ) ) {
            return __( 'This task is paused. Resume it before running it.', 'mainwp' );
        }
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return __( 'Native backups are not enabled.', 'mainwp' );
        }
        $sites = static::resolve_task_run_sites( $task, $stale_sites );
        if ( empty( $sites ) ) {
            return ! empty( $stale_sites )
                ? __( 'No existing sites remain in this backup task. Re-save the task to select sites.', 'mainwp' )
                : __( 'No sites are selected for this backup task. Edit the task and select sites.', 'mainwp' );
        }
        foreach ( $sites as $site_id ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( empty( $website ) || ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site_id ) ) {
                return __( 'You are not allowed to run backups for one or more of this task\'s sites.', 'mainwp' );
            }
            if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
                return __( 'A restore is in progress for this site. Try again once it finishes.', 'mainwp' );
            }
            // Back Up Now's hidden task targets one site, so a disconnected site
            // refuses here like the Back Up Now button does. A scheduled task
            // still runs its connected sites; a disconnected one fails on its own.
            if ( 1 === (int) $task->template && MainWP_Post_Backup_Handler::site_is_disconnected( $website ) ) {
                return MainWP_Post_Backup_Handler::disconnected_site_error();
            }
        }
        return '';
    }

    /**
     * Advance a saved task and report recorded snapshots for its sites.
     *
     * @param int        $task_id Task id.
     * @param int        $started Owned run timestamp, or zero when no run is owned.
     * @param int        $click   Click timestamp while queued or adopting a close, otherwise zero.
     * @param bool       $retried Whether this call is the single re-entry after a mid-assembly claim.
     * @param int|null   $baseline Run identity before the click; null disables adoption.
     * @param float|null $deadline Shared deadline on re-entry; null starts a status request budget.
     * @param bool       $driven Whether an earlier call in this request visited a site.
     * @return array Task and site status, or error.
     */
    public static function get_backup_task_run_status( int $task_id, int $started = 0, int $click = 0, bool $retried = false, ?int $baseline = null, ?float $deadline = null, bool $driven = false ): array {
        if ( null === $deadline ) {
            $request_started = microtime( true );
            $deadline        = $request_started + max( 0.0, (float) apply_filters( 'mainwp_backup_status_poll_budget', 25 ) );
        }
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( empty( $task ) || ! static::can_edit_backuptask( $task ) ) {
            return array( 'error' => esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) );
        }
        $error = static::manual_task_start_error( $task );
        if ( '' !== $error ) {
            return array( 'error' => $error );
        }
        MainWP_Post_Backup_Handler::raise_backup_resource_limits();
        if ( $started > 0 && (int) $task->last_run === $started && (int) $task->completed < $started ) {
            $claimed         = null;
            $claimed_started = 0;
            $outcome         = null;
            static::execute_backup_task( $task, 5, false, 0, $claimed, $claimed_started, false, $outcome, $deadline );
            $driven = $driven || $outcome['sites_visited'] > 0;
            $task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        } elseif ( 0 === $started && $click > 0 && $click <= time() && (int) $task->completed >= (int) $task->last_run ) {
            if ( static::can_adopt_closed_task_run( $task, $baseline, $click ) ) {
                return static::get_backup_task_run_status( $task_id, (int) $task->last_run, $click, false, $baseline, $deadline, $driven );
            }
            $run = static::claim_manual_task_run( $task, $click, $baseline, $deadline, $driven );
            if ( isset( $run['error'] ) ) {
                return $run;
            }
            $started = $run['started'];
            $click   = $run['click'];
        }
        if ( empty( $task ) ) {
            return array( 'error' => esc_html__( 'The backup task could not be started. Reload the page and try again.', 'mainwp' ) );
        }

        $saved = false;
        if ( $started > 0 && (int) $task->last_run !== $started ) {
            $saved = get_transient( 'mainwp_backup_run_' . $task_id . '_' . $started );
            if ( ! is_array( $saved ) || ! isset( $saved['task'] ) || ! is_object( $saved['task'] ) || ! isset( $saved['progress'], $saved['snapshots'] ) ) {
                return array(
                    'error' => esc_html__( 'The result for this backup run is no longer available. Reload the page to see the latest run.', 'mainwp' ),
                    'code'  => 'run_not_found',
                );
            }
            $task  = $saved['task'];
            $error = static::manual_task_start_error( $task );
            if ( ! static::can_edit_backuptask( $task ) || '' !== $error ) {
                return array( 'error' => '' !== $error ? $error : esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) );
            }
        }
        $owns_run    = $started > 0 && (int) $task->last_run === $started;
        $queued      = 0 === $started && $click > 0 && $click <= time();
        $open        = $owns_run && (int) $task->completed < $started;
        $cutoff      = $started;
        $sites       = array();
        $stale_sites = array();
        $complete    = 0;
        $errors      = $owns_run ? MainWP_Post_Backup_Handler::humanize_backup_errors( $task->backup_errors ) : '';
        $notices     = $owns_run ? MainWP_Post_Backup_Handler::humanize_backup_errors( $task->backup_notices ?? '' ) : '';
        foreach ( static::resolve_task_run_sites( $task, $stale_sites ) as $site_id ) {
            $website  = MainWP_DB::instance()->get_website_by_id( $site_id );
            $progress = $saved ? ( $saved['progress'][ $site_id ] ?? null ) : MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $site_id );
            if ( ! $owns_run || $queued || ( $progress && (int) $progress->dtsFetched < max( $cutoff, (int) $task->last_run ) ) ) {
                $progress = null;
            }
            $detail   = MainWP_Post_Backup_Handler::build_task_progress_response( $progress );
            $snapshot = $saved ? ( $saved['snapshots'][ $site_id ] ?? null ) : MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task_id );
            if ( ! $owns_run || $queued || ( $snapshot && (int) $snapshot->created < $cutoff ) ) {
                $snapshot = null;
            }
            $status  = $progress ? 'running' : 'pending';
            $uploads = null;
            if ( $snapshot ) {
                ++$complete;
                $status  = 'completed';
                $uploads = array(
                    'total'    => 0,
                    'complete' => 0,
                    'failed'   => 0,
                    'pending'  => 0,
                );
                foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $snapshot->id ) as $job ) {
                    ++$uploads['total'];
                    if ( 'complete' === $job->status ) {
                        $bucket = 'complete';
                    } elseif ( in_array( $job->status, array( 'failed_retryable', 'failed_permanent', 'credentials_invalid' ), true ) ) {
                        $bucket = 'failed';
                    } else {
                        $bucket = 'pending';
                    }
                    ++$uploads[ $bucket ];
                }
            } elseif ( ( ! $open && ! $queued ) || 'failed' === ( $detail['status'] ?? '' ) ) {
                $status = 'failed';
            }
            $sites[] = array(
                'id'           => (int) $site_id,
                'name'         => $website ? (string) $website->name : '',
                'url'          => $website ? (string) $website->url : '',
                'status'       => $status,
                'phase'        => $detail['phase'] ?? '',
                'label'        => $detail['label'] ?? '',
                'percent'      => $detail['percent'] ?? null,
                'current_file' => $detail['current_file'] ?? '',
                'error'        => 'failed' === $status && $progress ? MainWP_Post_Backup_Handler::humanize_backup_errors( $progress->last_error ) : '',
                'snapshot'     => $snapshot ? array(
                    'id'        => (int) $snapshot->id,
                    'type'      => $snapshot->type,
                    'parent_id' => null === $snapshot->parent_id ? null : (int) $snapshot->parent_id,
                    'created'   => (int) $snapshot->created,
                ) : null,
                'uploads'      => $uploads,
            );
        }
        $status = 'failed';
        if ( $open ) {
            $status = 'running';
        } elseif ( $queued ) {
            $status = 'queued';
        } elseif ( $complete > 0 ) {
            $status = count( $sites ) === $complete && '' === $errors ? 'completed' : 'completed_with_errors';
        }
        if ( $owns_run && ! $saved && ! $retried ) {
            $current = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
            if ( $current && (int) $current->last_run !== $started ) {
                // A new claim can replace progress while this response is being
                // assembled. One re-entry is enough: last_run only moves forward,
                // so the re-entry reads the saved copy of this run and returns.
                return static::get_backup_task_run_status( $task_id, $started, $click, true, $baseline, $deadline, $driven );
            }
        }
        return array(
            'status'      => $status,
            'run_state'   => $saved ? 'closed' : ( $owns_run ? ( $open ? 'open' : 'closed' ) : 'unowned' ),
            'stale_sites' => $stale_sites,
            'started'     => $started,
            'click'       => $click,
            'baseline'    => $baseline,
            'run_open'    => (int) $task->completed < (int) $task->last_run,
            'errors'      => $errors,
            'notices'     => $notices,
            'sites'       => $sites,
            'lane'        => $driven ? 'driver' : ( (int) $task->completed < (int) $task->last_run && MainWP_Backup_Monitor::has_live_lane_lease() ? 'busy' : 'idle' ),
        );
    }

    /**
     * Can Edit Backup Task.
     *
     * @param object $task Backup task.
     *
     * @return bool true|false.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
     */
    public static function can_edit_backuptask( &$task ) {
        if ( null === $task ) {
            return false;
        }

        if ( MainWP_System::instance()->is_single_user() ) {
            return true;
        }

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        return $task->userid === $current_user->ID;
    }

    /**
     * Verify the current user may target every site a task's sites/groups
     * selection covers, before the selection is stored. Write time is the
     * only place this can gate: once the row exists, the scheduled cron
     * executes it with no user context to re-check against, so an
     * out-of-scope site id stored here would be backed up unchecked
     * forever. Groups are expanded to their member sites the same way a
     * scheduled run resolves them, so selecting a group is not a bypass
     * for the per-site check the direct sites list gets. A site id that
     * does not resolve to a website row is refused too.
     *
     * @param string $sites  Comma-separated site ids.
     * @param string $groups Comma-separated group ids.
     *
     * @return string '' when authorized, an error message otherwise.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function authorize_task_scope( $sites, $groups ) {
        foreach ( static::expand_task_site_ids( $sites, $groups ) as $site_id ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( empty( $website ) || ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site_id ) ) {
                return esc_html__( 'You are not allowed to schedule backups for one or more of the selected sites.', 'mainwp' );
            }
        }

        return '';
    }

    /**
     * Authorize a task edit against BOTH the pre-edit and the replacement
     * scope. Authorizing only the new selection lets a caller who cannot
     * reach a task's current sites re-scope it to sites they can reach and
     * then delete it - the delete gate would see the laundered scope while
     * the snapshot purge still enumerates the task's historical sites and
     * destroys backups the caller was never allowed to touch. Requiring
     * access to the current scope too closes that laundering path at the
     * entry point.
     *
     * @param string $old_sites  The task's current comma-separated site ids.
     * @param string $old_groups The task's current comma-separated group ids.
     * @param string $new_sites  The replacement comma-separated site ids.
     * @param string $new_groups The replacement comma-separated group ids.
     *
     * @return string '' when both scopes are authorized, an error message otherwise.
     */
    public static function authorize_task_update_scope( $old_sites, $old_groups, $new_sites, $new_groups ) {
        $pre_edit = static::authorize_task_scope( $old_sites, $old_groups );
        if ( '' !== $pre_edit ) {
            return $pre_edit;
        }
        return static::authorize_task_scope( $new_sites, $new_groups );
    }

    /**
     * Expand a task's sites/groups selection into one deduplicated site-id
     * list - the same expansion authorize_task_scope() checks, so a
     * snapshot stamped from it is by construction the authorized set.
     *
     * @param string $sites  Comma-separated site ids.
     * @param string $groups Comma-separated group ids.
     *
     * @return int[] Site ids.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_table_name()
     */
    public static function expand_task_site_ids( $sites, $groups ) {
        global $wpdb;
        $site_ids = array();

        foreach ( explode( ',', (string) $sites ) as $site_id ) {
            if ( (int) $site_id > 0 ) {
                $site_ids[] = (int) $site_id;
            }
        }

        foreach ( explode( ',', (string) $groups ) as $group_id ) {
            if ( (int) $group_id <= 0 ) {
                continue;
            }
            // Keep all membership ids; the resolvers check existence and authorization separately.
            $group_sites = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- unscoped membership lookup must not depend on wp_sync.
                $wpdb->prepare( 'SELECT wpid FROM %i WHERE groupid = %d', MainWP_DB::instance()->get_table_name( 'wp_group' ), (int) $group_id )
            );
            foreach ( (array) $group_sites as $site_id ) {
                $site_ids[] = (int) $site_id;
            }
        }

        return array_values( array_unique( $site_ids ) );
    }

    /**
     * Persist the authorized-site snapshot on the task row.
     *
     * Called on every save, after authorize_task_scope() passed, so the
     * stored set is exactly what the saving user was allowed to target.
     * The scheduled runner backs up only this set: cron runs with no user
     * context (the allow-list layer early-returns under DOING_CRON), so a
     * site that joins one of the task's groups later cannot be authorized
     * there and stays out until an authorized re-save refreshes the
     * snapshot.
     *
     * @param int    $task_id Task id.
     * @param string $sites   Comma-separated site ids.
     * @param string $groups  Comma-separated group ids.
     *
     * @return int[] The stamped site ids.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function stamp_task_scope_snapshot( $task_id, $sites, $groups ) {
        $site_ids = static::expand_task_site_ids( $sites, $groups );
        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, array( 'authorized_sites' => implode( ',', $site_ids ) ) );
        return $site_ids;
    }

    /**
     * Resolve the site ids a scheduled run may back up.
     *
     * Resolution is read-only. Deleted sites and sites outside the authorized
     * snapshot are returned separately for the run lane to record notices.
     * A never-stamped legacy task resolves its live expansion; the run lane
     * persists that snapshot before driving the sites.
     *
     * @param object $task Task row.
     * @param array  $stale_sites Deleted site ids omitted from the run.
     * @param array  $unauthorized_sites Existing site ids outside the snapshot.
     *
     * @return int[] Site ids to run.
     */
    public static function resolve_task_run_sites( $task, &$stale_sites = array(), &$unauthorized_sites = array() ) {
        // A run keeps the columns' either/or precedence: a task with groups
        // runs its groups' members; only a groupless task runs the sites
        // column (the pre-snapshot runner resolved selections the same way).
        $expanded = static::expand_task_site_ids( empty( $task->groups ) ? $task->sites : '', $task->groups );

        // Existence is checked unscoped: a scoped read would report a site the
        // owner lost access to as deleted and skip the scope-pause below.
        $stale_sites        = array();
        $unauthorized_sites = array();
        $existing           = MainWP_DB::instance()->get_existing_website_ids( $expanded );
        foreach ( $expanded as $key => $site_id ) {
            if ( ! in_array( (int) $site_id, $existing, true ) ) {
                $stale_sites[] = $site_id;
                unset( $expanded[ $key ] );
            }
        }
        $expanded = array_values( $expanded );

        // NULL is the never-stamped sentinel: a row created before the
        // authorized_sites column existed. It adopts its live expansion once
        // (preserving its behavior at the upgrade instant) and arms the gate.
        // A stamped empty string is authoritative - it means the save
        // authorized zero sites, NOT "adopt whatever the group holds now" -
        // so an empty-saved group can never lazily auto-authorize a member
        // added later (that would reopen the very bypass this snapshot closes).
        if ( ! isset( $task->authorized_sites ) || null === $task->authorized_sites ) {
            return $expanded;
        }

        $authorized = static::authorized_snapshot_ids( $task );

        $run_sites = array();
        foreach ( $expanded as $site_id ) {
            if ( in_array( $site_id, $authorized, true ) ) {
                $run_sites[] = $site_id;
                continue;
            }
            $unauthorized_sites[] = $site_id;
        }

        return $run_sites;
    }

    /**
     * The task's authorized-site snapshot as an int id list, or null when
     * the row was never stamped (a pre-migration legacy row).
     *
     * @param object $task Task row.
     *
     * @return int[]|null Authorized site ids, or null for a never-stamped row.
     */
    public static function authorized_snapshot_ids( $task ) {
        if ( ! isset( $task->authorized_sites ) || null === $task->authorized_sites ) {
            return null;
        }
        return array_values( array_filter( array_map( 'intval', explode( ',', (string) $task->authorized_sites ) ) ) );
    }

    /**
     * The sites a stale-reset may act on: the live expansion restricted to
     * the authorized snapshot, with no side effects (no drift notice, no
     * backfill). A site added to a task's group after the snapshot was saved
     * is excluded, so reset never sends a cancel for a site the task does not
     * actually run - which the child would otherwise resolve against that
     * site's single active (possibly unrelated) backup.
     *
     * @param object $task Task row.
     *
     * @return int[] Site ids reset may act on.
     */
    public static function resolve_reset_task_site_ids( $task ) {
        $expanded   = static::expand_task_site_ids( empty( $task->groups ) ? $task->sites : '', $task->groups );
        $authorized = static::authorized_snapshot_ids( $task );

        // A never-stamped legacy row has no snapshot to restrict against;
        // reset acts on its full live expansion (its pre-migration behavior).
        if ( null === $authorized ) {
            return $expanded;
        }

        return array_values( array_intersect( $expanded, $authorized ) );
    }

    /**
     * Resolve a user's live Team-Control-allowed site ids without relying
     * on request context. mainwp_current_user_have_right() short-circuits
     * to true under DOING_CRON/WP_CLI/REST, so a scheduled run cannot ask
     * it anything; the allowed-sites filter itself has no such bypass and
     * Team Control resolves it from the current user at call time, so a
     * bounded current-user swap yields the owner's real scope from cron.
     *
     * @param int $user_id User id to resolve.
     *
     * @return int[]|null Allowed site ids when the user is restricted,
     *                    null when unrestricted (no Team Control, or the
     *                    extension grants full access). A nonexistent user
     *                    resolves to an empty array: an ownerless task
     *                    must not keep executing unrestricted forever.
     */
    public static function resolve_owner_allowed_site_ids( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 || false === get_user_by( 'id', $user_id ) ) {
            return array();
        }

        $previous_user_id = get_current_user_id();
        wp_set_current_user( $user_id );
        try {
            /** This filter is documented in class/class-mainwp-system.php */
            $allowed = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );
        } finally {
            wp_set_current_user( $previous_user_id );
        }

        // Only an explicit id list restricts; 'all' (or an unexpected
        // shape) reads as unrestricted, matching how every other consumer
        // of this filter treats its result.
        if ( ! is_array( $allowed ) ) {
            return null;
        }

        return array_values( array_map( 'intval', $allowed ) );
    }

    /**
     * Decide whether a scheduled run must pause instead of executing,
     * from the owner's live authorization. An unrestricted owner keeps
     * every existing behavior, including the one-time NULL-sentinel
     * backfill. A restricted owner pauses when the row was never stamped
     * (the backfill would grant per-site authority no save ever verified)
     * or when the stamped run set escapes their live scope - the task
     * stays paused until an authorized re-save confirms its scope.
     *
     * @param object $task Task row.
     *
     * @return string|null Pause reason ('unstamped'|'narrowed'), or null
     *                     when the run may proceed.
     */
    public static function resolve_task_scope_pause( $task ) {
        $allowed = static::resolve_owner_allowed_site_ids( isset( $task->userid ) ? (int) $task->userid : 0 );
        if ( null === $allowed ) {
            return null;
        }

        $authorized = static::authorized_snapshot_ids( $task );
        if ( null === $authorized ) {
            return 'unstamped';
        }

        $expanded = static::expand_task_site_ids( empty( $task->groups ) ? $task->sites : '', $task->groups );
        $existing = MainWP_DB::instance()->get_existing_website_ids( $expanded );
        foreach ( array_intersect( $expanded, $authorized ) as $site_id ) {
            if ( in_array( (int) $site_id, $existing, true ) && ! in_array( (int) $site_id, $allowed, true ) ) {
                return 'narrowed';
            }
        }

        return null;
    }

    /**
     * Pause a task whose owner's authorization no longer covers it. The
     * notice deliberately names no sites: it may be read (or mailed by a
     * future consumer) in contexts where enumerating sites the owner can
     * no longer access would itself disclose them. update_backup_notices()
     * refuses the exact message twice, so re-evaluated ticks (a resume
     * without a re-save) do not accumulate it.
     *
     * @param object $task Task row; scope is checked again after reloading.
     *
     * @return void
     */
    public static function pause_task_for_rescope( $task ) {
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
        if ( empty( $task ) ) {
            return;
        }
        $reason = static::resolve_task_scope_pause( $task );
        if ( null === $reason ) {
            return;
        }
        MainWP_DB_Backup::instance()->update_backup_task_with_values(
            $task->id,
            array(
                'paused'    => 1,
                'completed' => max( (int) $task->completed, (int) $task->last_run ),
            )
        );
        MainWP_DB_Backup::instance()->update_backup_notices(
            $task->id,
            esc_html__( 'This task was paused because its owner\'s site access changed or could not be confirmed. Re-save the task to confirm its scope, then resume it.', 'mainwp' ) . '<br />'
        );

        /**
         * Fires when a scheduled run pauses a task instead of executing
         * it, because the owner's live authorization no longer covers the
         * task's stored scope.
         *
         * @param int    $task_id Task id.
         * @param string $reason  'unstamped' (a pre-snapshot row whose
         *                        backfill cannot be authorized) or
         *                        'narrowed' (the stamped run set escapes
         *                        the owner's live scope).
         */
        do_action( 'mainwp_backup_task_auto_paused', (int) $task->id, $reason );
    }

    /**
     * Update backup task.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::add_backup_task()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task()
     */
    public static function update_backup() {  //phpcs:ignore -- NOSONAR - complex.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        //phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( '' === $name ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
        }

        $backupId = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupId );

        if ( ! static::can_edit_backuptask( $task ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) ) ) );
        }

        $schedule = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( ! in_array( $type, array( 'full', 'db', 'incremental', 'files' ), true ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup type. Select Full, Database, Incremental, or Files Only and try again.', 'mainwp' ) ) ) );
        }

        $excludedFolder = isset( $_POST['exclude'] ) ? trim( wp_unslash( $_POST['exclude'] ), "\n" ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
        $excludedFolder = explode( "\n", $excludedFolder );
        $excludedFolder = array_map( array( MainWP_Utility::get_class_name(), 'trim_slashes' ), $excludedFolder );
        $excludedFolder = array_map( 'htmlentities', $excludedFolder );
        $excludedFolder = MainWP_Backup_Settings::sanitize_exclude_list( $excludedFolder );
        $sites          = '';
        $groups         = '';

        if ( isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) as $site ) {
                if ( '' !== $sites ) {
                    $sites .= ',';
                }
                $sites .= $site;
            }
        }

        if ( isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) as $group ) {
                if ( '' !== $groups ) {
                    $groups .= ',';
                }
                $groups .= $group;
            }
        }

        // Authorize the pre-edit scope as well as the replacement: a caller
        // who cannot reach the task's current sites must not be able to
        // re-scope it to sites they can reach and then delete it to launder
        // the per-site purge gate (the purge enumerates the task's historical
        // sites, not just the current selection).
        $scope_error = static::authorize_task_update_scope( $task->sites, $task->groups, $sites, $groups );
        if ( '' !== $scope_error ) {
            die( wp_json_encode( array( 'error' => $scope_error ) ) );
        }

        do_action( 'mainwp_update_backuptask', $task->id );

        $archiveFormat                  = isset( $_POST['archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['archiveFormat'] ) ) : 'site';
        $maximumFileDescriptorsOverride = isset( $_POST['maximumFileDescriptorsOverride'] ) && 1 === (int) $_POST['maximumFileDescriptorsOverride'];
        $maximumFileDescriptorsAuto     = isset( $_POST['maximumFileDescriptorsAuto'] ) && 1 === (int) $_POST['maximumFileDescriptorsAuto'];
        $maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) ? intval( $_POST['maximumFileDescriptors'] ) : 150;
        $loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;
        $zipForceOverride               = ( isset( $_POST['zipForceOverride'] ) && 1 === (int) $_POST['zipForceOverride'] ) ? 1 : 0;

        $subfolder     = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
        $filename      = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        $excludebackup = isset( $_POST['excludebackup'] ) ? sanitize_text_field( wp_unslash( $_POST['excludebackup'] ) ) : '';
        $excludecache  = isset( $_POST['excludecache'] ) ? sanitize_text_field( wp_unslash( $_POST['excludecache'] ) ) : '';
        $excludenonwp  = isset( $_POST['excludenonwp'] ) ? sanitize_text_field( wp_unslash( $_POST['excludenonwp'] ) ) : '';
        $excludezip    = isset( $_POST['excludezip'] ) ? sanitize_text_field( wp_unslash( $_POST['excludezip'] ) ) : '';

        //phpcs:enable.Missing

        if ( MainWP_DB_Backup::instance()->update_backup_task( $task->id, $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, $subfolder, $filename, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, $zipForceOverride ) === false ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ) ) ) );
        } else {
            static::stamp_task_scope_snapshot( $task->id, $sites, $groups );
            $destinations = isset( $_POST['destinations'] ) && is_array( $_POST['destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destinations'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
            MainWP_Backup_Storage_Handler::save_task_destinations( $task->id, $destinations );
            static::save_task_schedule_fields( $task->id );
            static::save_task_retention_fields( $task->id );
            die( wp_json_encode( array( 'result' => esc_html__( 'Task updated successfully.', 'mainwp' ) ) ) );
        }
    }

    /** Add backup task. */
    public static function add_backup() {  //phpcs:ignore -- NOSONAR - complex.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        //phpcs:disable WordPress.Security.NonceVerification -- ok.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( empty( $name ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
        }

        $schedule = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( ! in_array( $type, array( 'full', 'db', 'incremental', 'files' ), true ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup type. Select Full, Database, Incremental, or Files Only and try again.', 'mainwp' ) ) ) );
        }

        $excludedFolder = isset( $_POST['exclude'] ) ? trim( wp_unslash( $_POST['exclude'] ), "\n" ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
        $excludedFolder = explode( "\n", $excludedFolder );
        $excludedFolder = array_map( array( MainWP_Utility::get_class_name(), 'trim_slashes' ), $excludedFolder );
        $excludedFolder = array_map( 'htmlentities', $excludedFolder );
        $excludedFolder = MainWP_Backup_Settings::sanitize_exclude_list( $excludedFolder );

        $sites  = '';
        $groups = '';

        if ( isset( $_POST['sites'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) as $site ) {
                if ( '' !== $sites ) {
                    $sites .= ',';
                }
                $sites .= $site;
            }
        }

        if ( isset( $_POST['groups'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) as $group ) {
                if ( '' !== $groups ) {
                    $groups .= ',';
                }
                $groups .= $group;
            }
        }

        $scope_error = static::authorize_task_scope( $sites, $groups );
        if ( '' !== $scope_error ) {
            die( wp_json_encode( array( 'error' => $scope_error ) ) );
        }

        $archiveFormat                  = isset( $_POST['archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['archiveFormat'] ) ) : 'site';
        $maximumFileDescriptorsOverride = isset( $_POST['maximumFileDescriptorsOverride'] ) && 1 === (int) $_POST['maximumFileDescriptorsOverride'];
        $maximumFileDescriptorsAuto     = isset( $_POST['maximumFileDescriptorsAuto'] ) && 1 === (int) $_POST['maximumFileDescriptorsAuto'];
        $maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) ? intval( $_POST['maximumFileDescriptors'] ) : 150;
        $loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;
        $zipForceOverride               = ( isset( $_POST['zipForceOverride'] ) && 1 === (int) $_POST['zipForceOverride'] ) ? 1 : 0;

        $subfolder     = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
        $filename      = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        $excludebackup = isset( $_POST['excludebackup'] ) ? sanitize_text_field( wp_unslash( $_POST['excludebackup'] ) ) : '';
        $excludecache  = isset( $_POST['excludecache'] ) ? sanitize_text_field( wp_unslash( $_POST['excludecache'] ) ) : '';
        $excludenonwp  = isset( $_POST['excludenonwp'] ) ? sanitize_text_field( wp_unslash( $_POST['excludenonwp'] ) ) : '';
        $excludezip    = isset( $_POST['excludezip'] ) ? sanitize_text_field( wp_unslash( $_POST['excludezip'] ) ) : '';
        //phpcs:enable

        $task = MainWP_DB_Backup::instance()->add_backup_task( $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, $subfolder, $filename, 0, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, $zipForceOverride );

        if ( ! $task ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ) ) ) );
        } else {
            static::stamp_task_scope_snapshot( $task->id, $sites, $groups );
            $destinations = isset( $_POST['destinations'] ) && is_array( $_POST['destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destinations'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
            MainWP_Backup_Storage_Handler::save_task_destinations( $task->id, $destinations );
            static::save_task_schedule_fields( $task->id );
            static::save_task_retention_fields( $task->id, true );

            do_action( 'mainwp_add_backuptask', $task->id );

            die( wp_json_encode( array( 'result' => esc_html__( 'Task created successfully.', 'mainwp' ) ) ) );
        }
    }

    /**
     * Validate and save the schedule fields posted with a task, and store
     * the computed next_run.
     *
     * Reads schedule_interval_hours, schedule_time_of_day,
     * schedule_day_of_week and schedule_day_of_month from the request the
     * caller already nonce-verified. Interval hours only apply to interval
     * tasks; a time of day only applies to daily, weekly and monthly tasks
     * and must match HH:MM. A task without either drops back to legacy
     * elapsed mode with next_run = 0.
     *
     * @param int $task_id Task id, already saved with its schedule.
     *
     * @return array The saved column values.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Schedule_Calculator::compute_next_run()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function save_task_schedule_fields( $task_id ) {
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( empty( $task ) ) {
            return array();
        }

        //phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
        $interval_hours = isset( $_POST['schedule_interval_hours'] ) ? absint( $_POST['schedule_interval_hours'] ) : 0;
        $time_raw       = isset( $_POST['schedule_time_of_day'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time_of_day'] ) ) : '';
        $day_of_week    = isset( $_POST['schedule_day_of_week'] ) ? min( 6, absint( $_POST['schedule_day_of_week'] ) ) : 0;
        $day_of_month   = isset( $_POST['schedule_day_of_month'] ) ? max( 1, min( 28, absint( $_POST['schedule_day_of_month'] ) ) ) : 1;
        //phpcs:enable

        $interval_hours = 'interval' === $task->schedule ? max( 1, min( 8760, $interval_hours ) ) : 0;

        $time_of_day = '';
        if ( in_array( $task->schedule, array( 'daily', 'weekly', 'monthly' ), true ) && preg_match( '/^(\d{1,2}):(\d{1,2})$/', $time_raw, $matches ) ) {
            $hour   = (int) $matches[1];
            $minute = (int) $matches[2];
            if ( $hour <= 23 && $minute <= 59 ) {
                $time_of_day = sprintf( '%02d:%02d', $hour, $minute );
            }
        }

        $values = array(
            'interval_hours' => $interval_hours,
            'time_of_day'    => $time_of_day,
            'day_of_week'    => $day_of_week,
            'day_of_month'   => $day_of_month,
        );

        $sched_task = (object) array_merge( array( 'schedule' => $task->schedule ), $values );

        $calculator         = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
        $values['next_run'] = $calculator->compute_next_run( $sched_task, empty( $task->last_run ) ? null : (int) $task->last_run );

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, $values );

        return $values;
    }

    /**
     * Save the retention policy fields posted with a task. Zero means no
     * limit; absent or junk values save as zero, except an absent count on
     * a new task, which keeps NEW_TASK_RETENTION_COUNT. The cloud copies
     * toggle persists explicitly so unchecking it sticks.
     *
     * @param int  $task_id  Task id.
     * @param bool $new_task Whether the task is being created.
     *
     * @return array The saved column values.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function save_task_retention_fields( $task_id, $new_task = false ) {
        //phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
        $values = array(
            'retention_count'      => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : ( $new_task ? static::NEW_TASK_RETENTION_COUNT : 0 ),
            'retention_days'       => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0,
            'retention_storage_mb' => isset( $_POST['retention_storage_mb'] ) ? absint( $_POST['retention_storage_mb'] ) : 0,
            'delete_cloud_copies'  => empty( $_POST['delete_cloud_copies'] ) ? 0 : 1,
            'max_chain_length'     => isset( $_POST['max_chain_length'] ) ? absint( $_POST['max_chain_length'] ) : 0,
        );
        //phpcs:enable

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, $values );

        return $values;
    }

    /**
     * Zeroed outcome shape shared by execute_backup_task() and execute_backup_task_run().
     */
    private static function default_execute_outcome(): array {
        return array(
            'sites_visited'    => 0,
            'sites_advanced'   => 0,
            'sites_finished'   => 0,
            'sites_yielded'    => 0,
            'slot_refused'     => false,
            'lease_lost'       => false,
            'run_closed'       => false,
            'wall_time'        => 0.0,
            'budget_exhausted' => false,
        );
    }

    /**
     * Execute the backup task.
     *
     * @param mixed      $task Task to perform.
     * @param integer    $nrOfSites Number of Child Sites to perform the task on.
     * @param bool       $updateRun ture|false.
     * @param integer    $run_grace_seconds Settle window for opening the run; 0
     *                                      (every scheduled caller) allows stuck-run
     *                                      recovery but refuses paused rows.
     * @param bool|null  $claimed Actual atomic claim result, null when no claim was attempted.
     * @param int        $claimed_started Exact identity returned by a successful claim.
     * @param bool       $manual_run Stamp the claimed identity as manual before any site work.
     * @param array|null $outcome Per-call site counts, refusal flags, and timing.
     * @param float      $deadline Absolute request deadline; zero disables the budget.
     *
     * @return mixed $errorOutput.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup_site()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::claim_backup_run()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_completed_sites()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_errors()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_completed()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_notification_email()
     * @uses \MainWP\Dashboard\MainWP_Format::format_email()
     * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize()
     */
    public static function execute_backup_task( $task, $nrOfSites = 0, $updateRun = true, $run_grace_seconds = 0, &$claimed = null, &$claimed_started = 0, $manual_run = false, &$outcome = null, float $deadline = 0.0 ) { // phpcs:ignore -- NOSONAR - not quite complex function.

        $started = microtime( true );
        $outcome = self::default_execute_outcome();

        $claimed         = null;
        $claimed_started = 0;

        if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
            $outcome['budget_exhausted'] = true;
            $outcome['wall_time']        = microtime( true ) - $started;
            return false;
        }

        // The owner's live authorization gates every scheduled entry
        // (start and continuation lanes both land here) before a slot is
        // held, a run is claimed, or a notification can fire.
        $pause_reason = static::resolve_task_scope_pause( $task );
        if ( null !== $pause_reason ) {
            static::pause_task_for_rescope( $task );
            $outcome['wall_time'] = microtime( true ) - $started;
            return false;
        }

        // Starters and manual polls share the continuation lane. A continuation
        // caller retains ownership of its outer scope.
        $owns_lane = ! MainWP_Backup_Monitor::has_lane_lease_scope();
        if ( $owns_lane && false === MainWP_Backup_Monitor::acquire_lane_lease() ) {
            $outcome['slot_refused'] = true;
            $outcome['wall_time']    = microtime( true ) - $started;
            return false;
        }
        $slot_token = false;
        try {
            if ( ! MainWP_Backup_Monitor::confirm_lane_lease() ) {
                return false;
            }
            $slot_token = MainWP_Backup_Monitor::acquire_backup_slot( MainWP_Backup_Settings::get_max_concurrent_backups(), 'native' );
            if ( false === $slot_token ) {
                $outcome['slot_refused'] = true;
                return false;
            }

            // Inside the lease scope every child request this driver makes
            // is bounded and stamps the slot row, so a driver that dies
            // mid-run frees its slot in minutes instead of two hours.
            MainWP_Backup_Monitor::begin_native_lease( $slot_token );
            return static::execute_backup_task_run( $task, $nrOfSites, $updateRun, $run_grace_seconds, $claimed, $claimed_started, $manual_run, $outcome, $deadline );
        } finally {
            if ( false !== $slot_token ) {
                MainWP_Backup_Monitor::end_native_lease( $slot_token );
                MainWP_Backup_Monitor::release_backup_slot( $slot_token );
            }
            if ( $owns_lane ) {
                MainWP_Backup_Monitor::release_lane_lease();
            }
            $outcome['wall_time'] = microtime( true ) - $started;
        }
    }

    /**
     * Run the backup task once a concurrency slot is held.
     *
     * @param mixed      $task Task to perform.
     * @param integer    $nrOfSites Number of Child Sites to perform the task on.
     * @param bool       $updateRun ture|false.
     * @param integer    $run_grace_seconds Settle window for opening the run; 0
     *                                      (every scheduled caller) keeps the
     *                                      unconditional bump.
     * @param bool|null  $claimed Actual atomic claim result.
     * @param int        $claimed_started Identity of the claimed run.
     * @param bool       $manual_run Stamp the claimed identity as manual before any site work.
     * @param array|null $outcome Per-call site counts, refusal flags, and timing.
     * @param float      $deadline Absolute request deadline; zero disables the budget.
     *
     * @return mixed $errorOutput.
     */
    private static function execute_backup_task_run( $task, $nrOfSites = 0, $updateRun = true, $run_grace_seconds = 0, &$claimed = null, &$claimed_started = 0, $manual_run = false, &$outcome = null, float $deadline = 0.0 ) { // phpcs:ignore -- NOSONAR - not quite complex function.

        $outcome = self::default_execute_outcome();

        if ( $updateRun ) {
            $previous = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
            if ( $previous && (int) $previous->last_run > 0 && (int) $previous->completed >= (int) $previous->last_run ) {
                // Pollers can adopt scheduled runs too; every closed result must survive the next claim clearing progress and errors.
                $saved = array(
                    'task'      => $previous,
                    'progress'  => array(),
                    'snapshots' => array(),
                );
                foreach ( static::resolve_task_run_sites( $previous ) as $site_id ) {
                    $saved['progress'][ $site_id ] = MainWP_DB_Backup::instance()->get_backup_task_progress( $previous->id, $site_id );
                    $snapshot                      = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $previous->id );
                    if ( $snapshot && (int) $snapshot->created >= (int) $previous->last_run && (int) $snapshot->created <= (int) $previous->completed ) {
                        $saved['snapshots'][ $site_id ] = $snapshot;
                    }
                }
                // The re-read row is authoritative and is what gets saved. A driver
                // that lost its lease can still flush its errors after another driver
                // stamped the close, so gating on the error and notice text saved
                // nothing at all in exactly that race and a later poll read
                // run_not_found instead of the errors. Only the run identity gates.
                $current = MainWP_DB_Backup::instance()->get_backup_task_by_id( $previous->id );
                if ( $current && (int) $current->last_run === (int) $previous->last_run && (int) $current->completed >= (int) $current->last_run ) {
                    $saved['task'] = $current;
                    set_transient( 'mainwp_backup_run_' . $previous->id . '_' . $previous->last_run, $saved, DAY_IN_SECONDS );
                }
            }
        }
        if ( $updateRun ) {
            $claimed = MainWP_DB_Backup::instance()->claim_backup_run( $task->id, $run_grace_seconds, $claimed_started );
            if ( ! $claimed ) {
                // Another driver already owns this run, or it closed
                // successfully within the settle window: yield without
                // touching the row, recording an error, or reaching the
                // child - the observed damage was exactly a second
                // preflight and an orphan child task from here.

                /**
                 * Fires when a run (re)open is refused, so the diagnostics
                 * recorder can capture the re-init trace the event stream
                 * otherwise shows only as a second preflight.
                 *
                 * @param int    $task_id   Task id.
                 * @param string $reason    Refusal reason.
                 * @param int    $last_run  The run-open stamp the row kept.
                 * @param int    $completed The close stamp the row kept.
                 */
                do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'claim_refused', (int) $task->last_run, (int) $task->completed );
                return false;
            }
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
        if ( empty( $task ) || ( $updateRun && (int) $task->last_run !== $claimed_started ) ) {
            $claimed         = false;
            $claimed_started = 0;
            return false;
        }
        if ( $updateRun && $manual_run ) {
            MainWP_DB_Backup::instance()->update_backup_run_manually( $task->id, $claimed_started );
        }
        if ( $updateRun ) {
            // Start each run with a clean error slate so the Manage Backups list
            // reflects only the latest run, never a stale error from a past one.
            // Continue-loop ticks pass $updateRun = false, so per-site errors
            // accumulated earlier in the same run are preserved. Notices follow
            // the same lifecycle: they describe the latest run only.
            MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '' );
            MainWP_DB_Backup::instance()->update_backup_notices( $task->id, '' );
        }

        $completed_sites = $task->completed_sites;

        if ( '' !== $completed_sites ) {
            $completed_sites = json_decode( $completed_sites, true );
        }

        if ( ! is_array( $completed_sites ) ) {
            $completed_sites = array();
        }

        $stale_sites        = array();
        $unauthorized_sites = array();
        $sites              = static::resolve_task_run_sites( $task, $stale_sites, $unauthorized_sites );
        if ( null === static::authorized_snapshot_ids( $task ) ) {
            MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'authorized_sites' => implode( ',', $sites ) ) );
        }
        foreach ( $stale_sites as $site_id ) {
            MainWP_DB_Backup::instance()->update_backup_notices(
                $task->id,
                sprintf(
                    /* translators: %d: deleted site id. */
                    esc_html__( 'Site %d no longer exists and was skipped. Re-save the task to update its sites.', 'mainwp' ),
                    $site_id
                ) . '<br />'
            );
        }
        foreach ( $unauthorized_sites as $site_id ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            $label   = empty( $website ) ? ( 'Site ' . $site_id ) : ( $website->name . ' (' . $website->url . ')' );
            MainWP_DB_Backup::instance()->update_backup_notices(
                $task->id,
                sprintf(
                    /* translators: %s: site name and url. */
                    esc_html__( '%s is not in this task\'s authorized site list and was skipped. Re-save the task to include it.', 'mainwp' ),
                    $label
                ) . '<br />'
            );
        }
        $errorOutput  = null;
        $errorEntries = array();
        if ( $updateRun && empty( $sites ) ) {
            $errorOutput    = ! empty( $stale_sites )
                ? esc_html__( 'No existing sites remain in this backup task. Re-save the task to select sites.', 'mainwp' )
                : esc_html__( 'No sites are selected for this backup task. Edit the task and select sites.', 'mainwp' );
            $errorEntries[] = $errorOutput;
        }

        $lastStartNotification = $task->lastStartNotificationSent;
        if ( $updateRun && ( 1 === get_option( 'mainwp_notificationOnBackupStart' ) ) && ( $lastStartNotification < $task->last_run ) ) {
            $email = MainWP_DB_Common::instance()->get_user_notification_email( $task->userid );
            if ( '' !== $email ) {
                $output = 'A scheduled backup has started with MainWP on ' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp() ) . ' for the following ' . count( $sites ) . ' sites:<br />';

                foreach ( $sites as $siteid ) {
                    $website = MainWP_DB::instance()->get_website_by_id( $siteid );
                    $output .= '&nbsp;&bull;&nbsp;<a href="' . $website->url . '">' . MainWP_Utility::get_nice_url( $website->url ) . '</a><br />';
                }

                $output .= '<br />Backup details:<br /><br />';
                $output .= '<strong>Backup task</strong> - ' . $task->name . '<br />';
                $output .= '<strong>Backup type</strong> - ' . MainWP_Backup_Page::backup_type_label( $task->type ) . '<br />';
                $output .= '<strong>Backup schedule</strong> - ' . strtoupper( $task->schedule ) . '<br />';

                $mail_title       = 'A Scheduled Backup has been Started - MainWP';
                $formated_content = MainWP_Format::format_email( $email, $output, $mail_title );
                // A mail send has no transport filter to lease against and
                // can block on an SMTP round trip, so tick before it rather
                // than let the lease run out inside wp_mail().
                MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );
                wp_mail( $email, $mail_title, $formated_content, 'content-type: text/html' );
                MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'lastStartNotificationSent' => time() ) );
            }
        }

        $currentCount = 0;

        foreach ( $sites as $siteid ) {
            // The work between two child requests - bookkeeping, DB writes,
            // hashing a downloaded artifact - carries no request timeout to
            // lease against, so tick the lease per site. And re-assert the
            // cron lanes' own no-limit policy: fetch_url_site sets PHP's
            // execution limit to the request bound and never restores it,
            // so the bound would otherwise leak into that same work.
            MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );
            MainWP_System_Utility::set_time_limit( 0 );

            if ( MainWP_Backup_Monitor::native_lease_lost() || ! MainWP_Backup_Monitor::confirm_lane_lease() ) {
                $outcome['lease_lost'] = true;
                // The slot was reclaimed under this holder and another
                // process may already own it, so driving another site would
                // run it under someone else's slot. The rows are left as
                // they are; the next cycle resumes the task from here.
                break;
            }

            if ( isset( $completed_sites[ $siteid ] ) && ( true === $completed_sites[ $siteid ] ) ) {
                // A completed-marked site whose validated artifact still
                // lacks its snapshot is re-driven, not skipped: nothing
                // else ever re-enters a marked site, so without this the
                // pending record never finishes and the close guard could
                // only delay the snapshot loss. The re-entry skips the
                // download blocks via the complete flags and goes straight
                // to the record; the check is TTL-bounded, so re-drives
                // cannot loop past the pending window.
                if ( 'record_pending' !== MainWP_DB_Backup::instance()->is_backup_site_resumable_for_run( $task, $siteid ) ) {
                    continue;
                }
            }
            if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
                $outcome['budget_exhausted'] = true;
                break;
            }
            $website = MainWP_DB::instance()->get_website_by_id( $siteid );

            // An earlier terminal mark can leave a cancel the child refused
            // while a runner held the lease. That task is still active on the
            // child and refuses this site's next create, so land the owed
            // cancel before asking for a new backup.
            static::flush_pending_child_cancel( $website );

            $_mark_detail = '';
            $backupResult = null;
            $advanced     = false;
            try {
                $subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $task->name ), $task->subfolder );

                ++$outcome['sites_visited'];
                $backupResult = MainWP_Backup_Executor::backup_site( $siteid, $task, $subfolder, $advanced, count( $sites ) > 1 );

                if ( false === $backupResult ) {
                    if ( ! $advanced ) {
                        ++$outcome['sites_yielded'];
                    }
                    continue;
                }

                if ( null === $errorOutput ) {
                    $errorOutput = '';
                }
                $error          = false;
                $tmpErrorOutput = '';
                if ( isset( $backupResult['error'] ) ) {
                    $tmpErrorOutput .= $backupResult['error'] . '<br />';
                    $error           = true;
                }
                if ( isset( $backupResult['ftp'] ) && 'success' !== $backupResult['ftp'] ) {
                    $tmpErrorOutput .= 'FTP: ' . $backupResult['ftp'] . '<br />';
                    $error           = true;
                }
                if ( isset( $backupResult['dropbox'] ) && 'success' !== $backupResult['dropbox'] ) {
                    $tmpErrorOutput .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
                    $error           = true;
                }

                if ( isset( $backupResult['amazon'] ) && 'success' !== $backupResult['amazon'] ) {
                    $tmpErrorOutput .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
                    $error           = true;
                }

                if ( $error ) {
                    $errorOutput   .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
                    $errorOutput   .= $tmpErrorOutput . '<br />';
                    $errorEntries[] = 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />' . $tmpErrorOutput;
                }
            } catch ( \Exception $e ) {
                if ( $e instanceof MainWP_Exception
                    && MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING === $e->get_message_error_code()
                    && MainWP_Backup_Executor::EXTRA_FOREIGN_ALREADY_RUNNING !== (string) $e->get_message_extra()
                    && (int) $task->completed < (int) $task->last_run
                ) {
                    // Another driver of this same open run holds the child's
                    // single backup slot right now - a create still resolving
                    // or a download lock in use. The collision is the run
                    // proceeding elsewhere, not this site failing, so it
                    // yields like a false return from backup_site() and skips
                    // the completion bookkeeping below. Recording it would
                    // close the run mid-flight and email a failure for a
                    // backup that goes on to succeed. A persistent foreign
                    // refusal is intentionally not yielded here; it falls
                    // through so the operator sees the real blocker.
                    MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Backup already running elsewhere in this run; yielding to the driver that holds the slot.' );

                    /**
                     * Fires when the site loop decides a site's fate for the
                     * run - marked complete or yielded - so the diagnostics
                     * recorder can name the driver of every completed_sites
                     * write. The close that lost a snapshot was untraceable
                     * exactly because these decisions left no event.
                     *
                     * @param int    $task_id Task id.
                     * @param int    $site_id Child site id.
                     * @param string $outcome success|error|yield_already_running|yield_resumable.
                     * @param string $detail  Exception class, code, and message for error marks.
                     */
                    do_action( 'mainwp_backup_diag_site_mark', (int) $task->id, (int) $siteid, 'yield_already_running', '' );
                    if ( ! $advanced ) {
                        ++$outcome['sites_yielded'];
                    }
                    continue;
                }

                $_mark_detail = get_class( $e );
                if ( $e instanceof MainWP_Exception && '' !== (string) $e->get_message_error_code() ) {
                    $_mark_detail .= ' [' . (string) $e->get_message_error_code() . ']';
                }
                $_mark_detail .= ': ' . substr( (string) $e->getMessage(), 0, 500 );
                if ( $e instanceof MainWP_Exception && '' !== (string) $e->get_message_extra() ) {
                    $_mark_detail .= ' (' . (string) $e->get_message_extra() . ')';
                }

                // The loop's task view can be seconds old, and that staleness
                // is exactly the race window: decide the site's fate against
                // a fresh row, not the one read before the child round trip.
                $_fresh_task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
                if ( $_fresh_task ) {
                    $task = $_fresh_task;
                }

                // A failed snapshot insert must surface now, even when downloads classify as record_pending.
                $_record_failed = $e instanceof MainWP_Exception && MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED === $e->get_message_error_code();
                $_resumable     = ( ! $_record_failed && (int) $task->completed < (int) $task->last_run )
                    ? MainWP_DB_Backup::instance()->is_backup_site_resumable_for_run( $task, $siteid )
                    : false;
                if ( false !== $_resumable ) {
                    // The exception hit a site whose row the next cycle can
                    // still finish - a claimed terminal mid-download, or a
                    // validated artifact waiting on its snapshot record.
                    // Marking it complete here is how a run closes mid-flight
                    // and loses the snapshot. Mid-download yields burn one
                    // retry attempt so a repeating throw exhausts the budget
                    // and marks honestly; record-pending yields are bounded
                    // by the artifact-age TTL instead.
                    if ( 'mid_download' === $_resumable ) {
                        MainWP_DB_Backup::instance()->consume_backup_attempt( $task->id, $siteid );
                    }
                    MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Backup exception against a still-resumable site; yielding for the retry (' . $_resumable . '): ' . $_mark_detail );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_site_mark', (int) $task->id, (int) $siteid, 'yield_resumable', $_mark_detail . ' (' . $_resumable . ')' );
                    if ( ! $advanced ) {
                        ++$outcome['sites_yielded'];
                    }
                    continue;
                }

                if ( null === $errorOutput ) {
                    $errorOutput = '';
                }
                $errorOutput .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
                $_error_code  = $e instanceof MainWP_Exception ? $e->get_message_error_code() : '';
                // Readiness failures need the Child's reason so the user can correct it.
                $_error_output = MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED !== $_error_code && in_array( $_error_code, MainWP_Backup_Error_Codes::get_registered_codes(), true ) ? MainWP_Backup_Error_Codes::message( $_error_code ) : MainWP_Error_Helper::get_error_message( $e );
                $errorOutput  .= $_error_output . '<br />';
                MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, (int) $siteid, array( 'last_error' => $_error_output ) );
                $errorEntries[] = 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />' . $_error_output;

                static::close_child_task_after_terminal_mark( (int) $task->id, (int) $siteid );
                if ( $_record_failed ) {
                    MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, (int) $siteid, array( 'child_phase' => MainWP_Backup_Task_Phase::FAILED ) );
                }
            } finally {
                if ( $advanced ) {
                    ++$outcome['sites_advanced'];
                }
                // A child request leaves PHP's execution limit at the bound it
                // gave itself, so the bookkeeping and hashing that follow would
                // inherit it instead of the cron lanes' no-limit policy.
                MainWP_System_Utility::set_time_limit( 0 );
                // Yielded sites also need a heartbeat before the next site starts.
                MainWP_Backup_Monitor::refresh_lane_lease();
            }

            if ( ! MainWP_Backup_Monitor::confirm_native_lease() || ! MainWP_Backup_Monitor::confirm_lane_lease() ) {
                $outcome['lease_lost'] = true;
                // The slot went away while this site was being driven, so
                // whatever the child did or did not do happened outside a
                // slot this holder owns. Marking the site complete would
                // retire it on the strength of that, so the loop stops
                // here and leaves the site for the next cycle.
                break;
            }

            $_backup_result = isset( $backupResult ) ? $backupResult : ( isset( $_error_output ) ? $_error_output : '' );
            do_action( 'mainwp_managesite_schedule_backup', $website, array( 'type' => $task->type ), $_backup_result );

            ++$currentCount;
            ++$outcome['sites_finished'];

            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

            $completed_sites = $task->completed_sites;

            if ( '' !== $completed_sites ) {
                $completed_sites = json_decode( $completed_sites, true );
            }
            if ( ! is_array( $completed_sites ) ) {
                $completed_sites = array();
            }

            /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
            do_action( 'mainwp_backup_diag_site_mark', (int) $task->id, (int) $siteid, '' === $_mark_detail ? 'success' : 'error', $_mark_detail );

            $completed_sites[ $siteid ] = true;
            MainWP_DB_Backup::instance()->update_completed_sites( $task->id, $completed_sites );

            if ( ( 0 !== $nrOfSites ) && ( $nrOfSites <= $currentCount ) ) {
                break;
            }
        }

        MainWP_System_Utility::set_time_limit( 0 );

        $lease_lost = ! MainWP_Backup_Monitor::confirm_native_lease() || ! MainWP_Backup_Monitor::confirm_lane_lease();
        if ( $lease_lost ) {
            $outcome['lease_lost'] = true;
            // Both loop exits above leave the run unfinished, so the reason
            // is recorded once here, on the same path a per-site failure
            // takes to the task row and the failure email.
            if ( null === $errorOutput ) {
                $errorOutput = '';
            }
            $_slot_lost     = MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SLOT_LOST );
            $errorOutput   .= $_slot_lost . '<br />';
            $errorEntries[] = $_slot_lost;
        }

        if ( null !== $errorOutput ) {
            MainWP_DB_Backup::instance()->update_backup_errors( $task->id, MainWP_Backup_Monitor::format_backup_errors( $errorEntries ) );
        }

        if ( $lease_lost ) {
            // The run is not finished, so none of the close bookkeeping may
            // fire: the task stays in the state get_backup_tasks_to_complete()
            // picks up on the next cycle.
            return false;
        }

        if ( $outcome['budget_exhausted'] ) {
            return false;
        }

        if ( count( $completed_sites ) === count( $sites ) ) {
            if ( MainWP_DB_Backup::instance()->has_live_child_task_for_run( $task->id ) ) {
                // A create request for this run has not resolved yet. Closing
                // now would stamp the run finished while it is still in
                // flight; the next continue pass that reaches this same
                // all-sites check retries the close.
                /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'close_refused_live_child', (int) $task->last_run, (int) $task->completed );
            } elseif ( MainWP_DB_Backup::instance()->has_pending_record_for_run( $task->id ) ) {
                // A validated artifact is still waiting on its snapshot
                // record. Closing now is the lost-snapshot race: the record
                // lands seconds later with no open run to credit. The
                // pending window is TTL-bounded, so a record that never
                // finishes releases the close instead of wedging it.
                /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'close_refused_pending_record', (int) $task->last_run, (int) $task->completed );
            } elseif ( MainWP_DB_Backup::instance()->update_backup_completed( $task->id ) ) {
                // Only the driver whose guarded close stamped the run owns
                // the close side effects: with backup_errors persisting on
                // the row for the on-demand poll to surface, this gate is
                // what keeps a racing second closer from re-sending the
                // failure email.

                /**
                 * Fires when a run is stamped closed, naming the trigger, so
                 * a bundle shows which path closed a run and when - the
                 * counterpart of the site_mark event at the site level.
                 *
                 * @param int    $task_id     Task id.
                 * @param int    $last_run    The run-open stamp being closed.
                 * @param string $trigger     Close trigger.
                 * @param int    $sites_total Number of sites in the run.
                 */
                $outcome['run_closed'] = true;
                do_action( 'mainwp_backup_diag_run_close', (int) $task->id, (int) $task->last_run, 'all_sites_complete', count( $sites ) );
                static::maybe_send_backup_error_email( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
            }
        }

        return empty( $errorOutput );
    }

    /**
     * Close out the child side of a site that just took a terminal
     * failure mark. A run that dies while its progress row still carries
     * a real child task id leaves that task ACTIVE on the child until its
     * wall-clock deadline (~48 minutes), refusing every backup on the
     * site for the duration - so the mark fires a best-effort precise-id
     * cancel. A task the child already finished is pruned instead: no
     * snapshot will ever name this run, so retention never would, and its
     * archive would stay on the site. A creating marker is never
     * cancelled: it is not an id the child knows, and an empty-id cancel
     * would auto-resolve to whatever
     * backup the child is running, possibly the foreign one this run just
     * collided with. The row is cleared either way (the child task id
     * dropped, the phase stamped terminal), because a phase-less row with
     * a task id projects "running / Waiting to start" to the progress
     * poll forever.
     *
     * @param int $task_id Task id.
     * @param int $site_id Site id.
     *
     * @return void
     */
    public static function close_child_task_after_terminal_mark( $task_id, $site_id ) {
        $progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task_id, (int) $site_id );
        if ( empty( $progress ) || '' === (string) $progress->child_backup_task_id ) {
            return;
        }

        $child_task_id  = (string) $progress->child_backup_task_id;
        $child_phase    = MainWP_Backup_Task_Phase::tryFrom( (string) $progress->child_phase );
        $child_terminal = MainWP_Backup_Task_Phase::COMPLETED === $child_phase || MainWP_Backup_Task_Phase::FAILED === $child_phase;
        if ( ! MainWP_Backup_Executor::is_creating_marker( $child_task_id ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
            $landed  = empty( $website ) || ( $child_terminal ? static::send_child_task_prune( $website, $child_task_id ) : static::send_child_task_cancel( $website, $child_task_id ) );
            if ( ! $landed ) {
                // The owed cancel converges either way: a cancel of a finished
                // task answers already terminal, which prunes it.
                static::record_pending_child_cancel( $website, $child_task_id );
            }
        }

        MainWP_DB_Backup::instance()->update_backup_task_progress(
            (int) $task_id,
            (int) $site_id,
            array(
                'child_backup_task_id' => '',
                'child_phase'          => MainWP_Backup_Task_Phase::FAILED,
            )
        );
    }

    /**
     * Ask the child to cancel a named backup task, and report whether the
     * task is actually gone.
     *
     * A child whose runner holds the task's lease answers a cancel with a
     * plain busy result, not an error: the task stays ACTIVE and keeps
     * refusing every backup on the site. Treating that response as success
     * is what let an abandoned task outlive the terminal mark, so busy - and
     * an unreachable child - report failure and earn a retry.
     *
     * Landed means the child answered about the task with an explicit
     * non-busy result (cancelled, nothing active), or answered already
     * terminal and then confirmed pruning it: a task that finished after the
     * Dashboard gave up still holds its archive. An error
     * payload or a malformed response proves nothing about the task - the
     * other cancel callers in this codebase read those as failures too - so
     * it stays owed, and the bounded retry absorbs the case where the error
     * was "task not found" (an id the child already reclaimed). That
     * not-found message is translatable, so it can never be string-matched
     * into a success.
     *
     * @param object $website       Website row.
     * @param string $child_task_id Child task id to cancel.
     *
     * @return bool True when the child no longer holds the task.
     */
    protected static function send_child_task_cancel( $website, $child_task_id ) {
        try {
            $information = MainWP_Connect::fetch_url_authed( $website, 'backup_task_cancel', array( 'task_id' => $child_task_id ), false, false, false );
        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Child cancel did not land: ' . $e->getMessage() );
            return false;
        }

        if ( ! is_array( $information ) || isset( $information['error'] ) || ! isset( $information['result'] ) ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Child cancel answered with an error or an unexpected shape (child task: ' . $child_task_id . '); a later pass retries it.' );
            return false;
        }

        if ( 'busy' === $information['result'] ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'The child refused the cancel as busy (child task: ' . $child_task_id . '); a later pass retries it.' );
            return false;
        }

        if ( 'already_terminal' === $information['result'] ) {
            return static::send_child_task_prune( $website, $child_task_id );
        }

        MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Backup cancelled on the child (child task: ' . $child_task_id . ')' );
        return true;
    }

    /**
     * Ask the child to delete a finished task the Dashboard abandoned, with
     * its artifacts, and report whether the task is gone.
     *
     * The child answers counts, not per-task outcomes, so the request names
     * one task. Only pruned or missing counts as gone; an active task, an
     * error or an unreadable answer is retried by a later pass. The sweep
     * stays off: it is housekeeping for retention's prune, not this run's.
     *
     * @param object $website       Website row.
     * @param string $child_task_id Child task id to prune.
     *
     * @return bool True when the child no longer holds the task.
     */
    protected static function send_child_task_prune( $website, $child_task_id ) {
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'backup_task_prune',
                array(
                    'task_ids' => wp_json_encode( array( (string) $child_task_id ) ),
                    'sweep'    => 0,
                ),
                false,
                false,
                false
            );
        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Child prune did not land: ' . $e->getMessage() );
            return false;
        }

        if ( ! MainWP_Backup_Executor::child_prune_confirmed( $information ) ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'The child did not confirm pruning a finished backup task (child task: ' . $child_task_id . '); a later pass retries it.' );
            return false;
        }

        MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Finished backup removed from the child (child task: ' . $child_task_id . ')' );
        return true;
    }

    /**
     * Read the cancels this site still owes the child.
     *
     * @param object $website Website row.
     *
     * @return array Map of child task id => attempts so far.
     */
    protected static function read_pending_child_cancels( $website ) {
        $raw = MainWP_DB::instance()->get_website_option( $website, static::PENDING_CANCEL_OPTION );
        if ( empty( $raw ) ) {
            return array();
        }

        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        // Earlier builds stored one { task_id, attempts } record; fold it in
        // so an owed cancel recorded before an upgrade is not dropped.
        if ( isset( $decoded['task_id'] ) ) {
            return array( (string) $decoded['task_id'] => (int) ( $decoded['attempts'] ?? 0 ) );
        }

        $pending = array();
        foreach ( $decoded as $child_task_id => $attempts ) {
            // PHP turns numeric-string keys into ints across a JSON round
            // trip, so an all-digits child task id would otherwise be
            // dropped by an is_string() check.
            if ( ( is_string( $child_task_id ) || is_int( $child_task_id ) ) && '' !== (string) $child_task_id ) {
                $pending[ (string) $child_task_id ] = (int) $attempts;
            }
        }
        return $pending;
    }

    /**
     * Remember a cancel the child refused, so a later pass can land it.
     *
     * The progress row is cleared by the terminal mark itself, so it cannot
     * carry the owed cancel: the site option is what survives to the next
     * pass. The option keys owed cancels by child task id, because a site can
     * belong to more than one backup task: two terminal marks close together
     * must both stay owed, not have the later one overwrite the earlier.
     *
     * Public because the terminal mark is not the only way a task ends up
     * unowned: a driver that loses its concurrency slot right after a
     * create holds the only copy of that child task id.
     *
     * @param object $website       Website row.
     * @param string $child_task_id Child task id still active on the child.
     *
     * @return void
     */
    public static function record_pending_child_cancel( $website, $child_task_id ) {
        $pending = static::read_pending_child_cancels( $website );
        if ( ! isset( $pending[ (string) $child_task_id ] ) ) {
            $pending[ (string) $child_task_id ] = 0;
        }
        MainWP_DB::instance()->update_website_option( $website, static::PENDING_CANCEL_OPTION, wp_json_encode( $pending ) );
    }

    /**
     * Retry every cancel the child refused, before this site is backed up again.
     *
     * An abandoned child task refuses the next create on the site, so the
     * owed cancels are landed at the top of the site's next run rather than
     * left for the child's wall clock. Each retry is capped: the child's own
     * queued reclaim and wall-clock deadline are the backstop, and an
     * unbounded retry would outlive the task it names.
     *
     * @param object $website Website row.
     *
     * @return void
     */
    public static function flush_pending_child_cancel( $website ) {
        if ( empty( $website ) ) {
            return;
        }

        $pending = static::read_pending_child_cancels( $website );
        if ( empty( $pending ) ) {
            return;
        }

        $landed = array();
        $owed   = array();
        foreach ( $pending as $child_task_id => $attempts ) {
            if ( static::send_child_task_cancel( $website, (string) $child_task_id ) ) {
                $landed[] = (string) $child_task_id;
                continue;
            }

            $next = (int) $attempts + 1;
            if ( $next >= static::PENDING_CANCEL_MAX_ATTEMPTS ) {
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Gave up cancelling an abandoned child backup task after ' . $next . ' attempts (child task: ' . $child_task_id . ')' );
                $landed[] = (string) $child_task_id;
                continue;
            }

            $owed[ (string) $child_task_id ] = $next;
        }

        // Re-read before writing back: the sends above are child round trips,
        // and a terminal mark can record a NEW owed cancel while they are in
        // flight. Writing the stale copy would silently drop it.
        $fresh = static::read_pending_child_cancels( $website );
        foreach ( $landed as $child_task_id ) {
            unset( $fresh[ $child_task_id ] );
        }
        foreach ( $owed as $child_task_id => $attempts ) {
            $fresh[ $child_task_id ] = $attempts;
        }

        MainWP_DB::instance()->update_website_option( $website, static::PENDING_CANCEL_OPTION, empty( $fresh ) ? '' : wp_json_encode( $fresh ) );
    }

    /**
     * Send the backup-failure notification for a finished run when real
     * errors were recorded. The errors stay on the row: the on-demand
     * progress poll arrives after the close and must still read them, or
     * a failed backup surfaces as the generic "did not produce a snapshot"
     * (which is exactly how a child's disk-full message went missing).
     * Duplicate emails are prevented by the caller - only the driver whose
     * guarded update_backup_completed() closed the run sends - and the next
     * run's claim resets the slate.
     *
     * Notices (backup_notices) never trigger this email: an informational
     * note like the large-site ZIP auto-switch is not a failure. The option
     * is cast before comparing because WordPress returns it as a string
     * across requests - the old strict int comparison made this gate dead
     * code on cron runs - and the default matches the settings screen (on).
     *
     * @param object|null $task Task row, freshly read.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_notification_email()
     * @uses \MainWP\Dashboard\MainWP_Format::format_email()
     */
    public static function maybe_send_backup_error_email( $task ) {
        if ( empty( $task ) || '' === (string) $task->backup_errors ) {
            return;
        }

        if ( 1 !== (int) get_option( 'mainwp_notificationOnBackupFail', 1 ) ) {
            return;
        }

        $email = MainWP_DB_Common::instance()->get_user_notification_email( $task->userid );
        if ( '' === $email ) {
            return;
        }

        $errorOutput      = 'Errors occurred while executing task: <strong>' . esc_html( $task->name ) . '</strong><br /><br />' . wp_kses_post( MainWP_Backup_Error_Codes::sanitize_message( $task->backup_errors ) );
        $mail_title       = 'A scheduled backup had an Error - MainWP';
        $formated_content = MainWP_Format::format_email( $email, $errorOutput, $mail_title );
        MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );
        wp_mail( $email, $mail_title, $formated_content, 'content-type: text/html' );
    }

    /**
     * Resolve the site ids a backup task's own sites/groups columns cover.
     *
     * Shared by backup() and get_backup_task_sites() so both scope a
     * caller-supplied or returned site strictly to what the task itself
     * lists - group membership is resolved the same way a scheduled run
     * would see it.
     *
     * @param object|null $backupTask Backup task row.
     *
     * @return int[] Site ids, deduplicated.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_id()
     */
    private static function resolve_task_site_ids( $backupTask ) {
        if ( empty( $backupTask ) ) {
            return array();
        }

        return static::expand_task_site_ids( empty( $backupTask->groups ) ? $backupTask->sites : '', $backupTask->groups );
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Prepare Child Site to be backed up.
     *
     * Authorization is layered: task ownership (parity with the sibling
     * remove/resume/pause handlers below - currently a no-op in production
     * since MainWP_System::is_single_user() is hardcoded true, kept for
     * parity and in case that ever changes), the caller-supplied site must
     * actually belong to this task's own site/group scope, and the Team
     * Control per-site capability, which is the gate that actually refuses
     * a restricted user today. Once authorized, the call is capped by the
     * same dashboard-wide concurrency slot execute_backup_task() enforces
     * for scheduled runs, so a Run Now click cannot stack past the
     * configured limit; a full slot throws a coded exception instead of
     * reaching the executor.
     *
     * @param mixed $pTaskId      Task ID.
     * @param mixed $pSiteId      Child Site ID.
     * @param mixed $pFileNameUID Filename Unique ID.
     *
     * @return self MainWP_Manage_Sites_Handler()
     *
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::resolve_task_site_ids()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Backup_Monitor::acquire_backup_slot()
     * @uses \MainWP\Dashboard\MainWP_Backup_Monitor::release_backup_slot()
     * @uses \MainWP\Dashboard\MainWP_Backup_Settings::get_max_concurrent_backups()
     * @uses \MainWP\Dashboard\MainWP_Backup_Error_Codes::exception()
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup()
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize()
     */
    public static function backup( $pTaskId, $pSiteId, $pFileNameUID ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $pTaskId );

        if ( ! static::can_edit_backuptask( $backupTask ) ) {
            throw new MainWP_Exception( esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) );
        }

        if ( ! in_array( (int) $pSiteId, static::resolve_task_site_ids( $backupTask ), true ) ) {
            throw new MainWP_Exception( esc_html__( 'This site is not part of the requested backup task.', 'mainwp' ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
        if ( ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $pSiteId ) ) {
            throw new MainWP_Exception( esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) );
        }

        $subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $backupTask->name ), $backupTask->subfolder );

        if ( 'site' === $backupTask->archiveFormat ) {
            $loadFilesBeforeZip             = false;
            $maximumFileDescriptorsOverride = false;
            $maximumFileDescriptorsAuto     = false;
            $maximumFileDescriptors         = 150;
            $archiveFormat                  = false;
        } elseif ( 'global' === $backupTask->archiveFormat ) {
            $loadFilesBeforeZip             = false;
            $maximumFileDescriptorsOverride = false;
            $maximumFileDescriptorsAuto     = false;
            $maximumFileDescriptors         = 150;
            $archiveFormat                  = 'global';
        } else {
            $loadFilesBeforeZip             = $backupTask->loadFilesBeforeZip;
            $maximumFileDescriptorsOverride = ( 'zip' === $backupTask->archiveFormat ) && ( 1 === (int) $backupTask->maximumFileDescriptorsOverride );
            $maximumFileDescriptorsAuto     = ( 'zip' === $backupTask->archiveFormat ) && ( 1 === (int) $backupTask->maximumFileDescriptorsAuto );
            $maximumFileDescriptors         = $backupTask->maximumFileDescriptors;
            $archiveFormat                  = $backupTask->archiveFormat;
        }

        // Cap concurrent backup tasks the same way execute_backup_task() does
        // for scheduled runs: a Run Now click cannot stack past the
        // configured limit and starve the dashboard resources the cap
        // protects. Unlike the scheduled path, a refused Run Now is reported
        // to the caller with a coded exception - the AJAX handler already
        // turns that into a clean JSON error the UI renders and advances
        // past, rather than a silent no-op the user has no way to explain.
        // claim_backup_run() deliberately does not apply here: that is
        // scheduled-run dedup (one driver owns a given run), not the
        // dashboard-wide concurrency cap this guard enforces.
        $slot_token = MainWP_Backup_Monitor::acquire_backup_slot( MainWP_Backup_Settings::get_max_concurrent_backups(), 'legacy' );
        if ( false === $slot_token ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_CONCURRENCY_LIMIT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        try {
            // The task rides along so preflight negotiation honors its keep-ZIP
            // override and records the outcome on it, the same as a scheduled run.
            return MainWP_Backup_Executor::backup( $pSiteId, $backupTask->type, $subfolder, $backupTask->exclude, $backupTask->excludebackup, $backupTask->excludecache, $backupTask->excludenonwp, $backupTask->excludezip, $backupTask->filename, $pFileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, false, false, $backupTask );
        } finally {
            MainWP_Backup_Monitor::release_backup_slot( $slot_token );
        }
    }

    /**
     * Get backup tasks and site ID.
     *
     * Ownership-gated like the sibling task handlers (see backup()'s
     * docblock for why that gate is currently a production no-op). The
     * returned site list is additionally filtered through the Team Control
     * per-site capability so a caller never sees data for a site their
     * access has been scoped away from, even when they otherwise own the
     * task.
     *
     * @param mixed $pTaskId Task ID.
     *
     * @return array $allSites All Sites array.
     * $remoteDestinations Remote destinations array.
     *
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::resolve_task_site_ids()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function get_backup_task_sites( $pTaskId ) {
        $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $pTaskId );

        if ( ! static::can_edit_backuptask( $backupTask ) ) {
            throw new MainWP_Exception( esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) );
        }

        $allSites = array();
        foreach ( static::resolve_task_site_ids( $backupTask ) as $site ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site );
            if ( empty( $website ) || ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site ) ) {
                continue;
            }
            $allSites[] = array(
                'id'       => $website->id,
                'name'     => $website->name,
                'fullsize' => $website->totalsize * 1024,
                'dbsize'   => $website->dbsize,
            );
        }

        $remoteDestinations = apply_filters( 'mainwp_backuptask_remotedestinations', array(), $backupTask );

        return array(
            'sites'              => $allSites,
            'remoteDestinations' => $remoteDestinations,
        );
    }

    /**
     * Remove Backup.
     *
     * Purges the task's restorable snapshots, cloud copies and upload
     * metadata before dropping the schedule row, so a deleted task never
     * leaves its backups orphaned and their storage leaking.
     */
    public static function remove_backup() {
        //phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by the AJAX router.
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        // Only an explicit 0 keeps the snapshots; older clients send nothing and purge as before.
        $delete_snapshots = ! isset( $_POST['delete_snapshots'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['delete_snapshots'] ) );
        //phpcs:enable WordPress.Security.NonceVerification.Missing
        die( wp_json_encode( static::remove_backup_result( $id, $delete_snapshots ) ) );
    }

    /**
     * Authorize, purge, and drop a backup task, returning the AJAX payload.
     *
     * Split from remove_backup() so the authorization and purge-failure
     * handling are testable without the die().
     *
     * Kept snapshots stay complete with their files and cloud copies; they
     * remain restorable, and Clean Up on the Snapshots page removes them
     * later as snapshots no task owns.
     *
     * @param int  $id               Task id.
     * @param bool $delete_snapshots Whether to purge the task's snapshots too.
     *
     * @return array Response payload: result | error | notask.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Backup_Retention_Executor::purge_snapshots_for_task()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::remove_backup_task()
     */
    public static function remove_backup_result( $id, $delete_snapshots = true ) {
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $id );
        if ( ! static::can_edit_backuptask( $task ) ) {
            return array( 'notask' => true );
        }

        // Deleting a task purges the snapshots and cloud copies of every
        // site it covers - an irreversible cross-site action. can_edit_backuptask()
        // above is a no-op under the default single-user mode, so gate this
        // on the same per-site access the run/reset/list paths enforce; a
        // Team-Control-restricted user must not be able to destroy the
        // backups of a site outside their scope.
        $scope_error = static::authorize_task_scope( $task->sites, $task->groups );
        if ( '' !== $scope_error ) {
            return array( 'error' => $scope_error );
        }

        // The purge walks the task's HISTORICAL sites (every site it ever ran
        // on), which can be broader than its current sites/groups after a
        // scope narrowing. Authorize that full set too, so a restricted user
        // cannot delete a task to destroy backups for a site now outside its
        // declared scope - and so the delete then safely purges the whole
        // history (an authorized deleter leaves nothing orphaned). A task with
        // no runs has no history and authorizes trivially.
        $historical = MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( (int) $task->id );
        if ( ! empty( $historical ) ) {
            $history_error = static::authorize_task_scope( implode( ',', array_map( 'intval', $historical ) ), '' );
            if ( '' !== $history_error ) {
                return array( 'error' => $history_error );
            }
        }

        // An open run holds Child task handles only its progress rows know.
        // Dropping the task would strand them, and a purge would race the
        // run's own download, so the run has to end first.
        if ( (int) $task->completed < (int) $task->last_run ) {
            return array( 'error' => esc_html__( 'This backup task is still running. Wait for the run to finish, or use Reset if it is stuck, then delete the task.', 'mainwp' ) );
        }

        if ( ! $delete_snapshots ) {
            // The task's diagnostics leave with it; the snapshots-purged
            // event would claim a purge that did not happen.
            MainWP_Backup_Diagnostics::instance()->purge_task_files( (int) $task->id );
            MainWP_DB_Backup::instance()->remove_backup_task( $task->id );
            return array( 'result' => 'SUCCESS' );
        }

        $purge_result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( (int) $task->id, ! empty( $task->delete_cloud_copies ) );

        // The purge deliberately keeps a snapshot when its artifact cannot be
        // reached (recorded outside the uploads root, or an unlink refused).
        // Dropping the task and its diagnostics anyway would orphan those
        // artifacts with no owning task for retention to retry, erase the
        // evidence, and still report success. Keep the task and its
        // diagnostics; surface the failure so the operator can act.
        if ( ! empty( $purge_result['errors'] ) ) {
            return array( 'error' => esc_html__( 'Some of this task\'s backups could not be removed, so the task was kept. Check the backup diagnostics and try again.', 'mainwp' ) );
        }

        /** This action is documented in class/class-mainwp-post-backup-handler.php */
        do_action( 'mainwp_backup_task_snapshots_purged', (int) $task->id, $purge_result );

        MainWP_DB_Backup::instance()->remove_backup_task( $task->id );
        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Resume Backup.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::resume_backup_result()
     */
    public static function resume_backup() {
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing
        die( wp_json_encode( static::resume_backup_result( $id ) ) );
    }

    /**
     * Authorize and resume a paused backup task, returning the AJAX payload.
     *
     * Split from resume_backup() so the authorization is testable without
     * the die().
     *
     * @param int|false $id Task id.
     *
     * @return array Response payload: result | notask.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function resume_backup_result( $id ) {
        if ( ! $id ) {
            return array( 'notask' => true );
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
        if ( ! static::can_edit_backuptask( $task ) ) {
            return array( 'notask' => true );
        }

        // Resuming re-arms scheduled runs across every site the task covers.
        // can_edit_backuptask() above is a no-op under the default
        // single-user mode, so gate this on the same per-site access the
        // run/reset/delete paths enforce.
        $scope_error = static::authorize_task_scope( $task->sites, $task->groups );
        if ( '' !== $scope_error ) {
            return array( 'notask' => true );
        }

        $values = array( 'paused' => 0 );

        // A next_run stored before the pause may be stale; recompute
        // so the task neither fires immediately nor skips a window.
        if ( (int) $task->next_run > 0 ) {
            $calculator         = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
            $values['next_run'] = $calculator->compute_next_run( $task, empty( $task->last_run ) ? null : (int) $task->last_run );
        }

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, $values );
        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Pause Backup.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::pause_backup_result()
     */
    public static function pause_backup() {
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing
        die( wp_json_encode( static::pause_backup_result( $id ) ) );
    }

    /**
     * Authorize and pause a scheduled backup task, returning the AJAX
     * payload.
     *
     * Split from pause_backup() so the authorization is testable without
     * the die().
     *
     * @param int|false $id Task id.
     *
     * @return array Response payload: result | notask.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function pause_backup_result( $id ) {
        if ( ! $id ) {
            return array( 'notask' => true );
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
        if ( ! static::can_edit_backuptask( $task ) ) {
            return array( 'notask' => true );
        }

        // Pausing stops scheduled runs across every site the task covers.
        // can_edit_backuptask() above is a no-op under the default
        // single-user mode, so gate this on the same per-site access the
        // run/reset/delete paths enforce.
        $scope_error = static::authorize_task_scope( $task->sites, $task->groups );
        if ( '' !== $scope_error ) {
            return array( 'notask' => true );
        }

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );
        return array( 'result' => 'SUCCESS' );
    }
}
