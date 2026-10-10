<?php
/**
 * Shared backup actions keep transport concerns outside their refusals.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Class MainWP_Backup_Operations. */
class MainWP_Backup_Operations { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Repeated fleet reads must not multiply site queries within a request.
     *
     * @var array<string,int[]>
     */
    private static $authorized_site_ids_memo = array();

    /**
     * Only registered codes select messages sent to agents.
     *
     * @param string $code Registered error code.
     * @return array Fixed error code and message.
     */
    public static function safe_error( string $code ): array {
        $house      = array(
            'mainwp_permission_denied'       => __( 'You do not have permission to perform this action.', 'mainwp' ),
            'mainwp_insufficient_right'      => __( 'Your role does not permit this operation.', 'mainwp' ),
            'mainwp_restricted_account'      => __( 'This action requires unrestricted site access.', 'mainwp' ),
            'mainwp_invalid_input'           => __( 'The supplied input is invalid.', 'mainwp' ),
            'mainwp_confirmation_required'   => __( 'Confirmation is required for this action.', 'mainwp' ),
            'mainwp_site_not_found'          => __( 'The site was not found.', 'mainwp' ),
            'mainwp_restore_rollback_status' => __( 'This restore cannot be rolled back in its current status.', 'mainwp' ),
            'mainwp_operation_failed'        => __( 'The child site did not return a usable response.', 'mainwp' ),
            'mainwp_internal_error'          => __( 'An unexpected error prevented this action.', 'mainwp' ),
        );
        $registered = MainWP_Backup_Error_Codes::get_messages();
        if ( ! preg_match( '/^mainwp_[a-z0-9_]+$/D', $code ) || ( ! isset( $registered[ $code ] ) && ! isset( $house[ $code ] ) ) ) {
            $code = MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR;
        }
        return array(
            'code'    => $code,
            'message' => wp_specialchars_decode( $house[ $code ] ?? MainWP_Backup_Error_Codes::message( $code ), ENT_QUOTES ),
        );
    }

    /**
     * Userless CLI is a trusted operator; REST needs an authenticated owner.
     *
     * @return bool
     */
    private static function userless_cli(): bool {
        return 0 === get_current_user_id() && defined( 'WP_CLI' ) && WP_CLI;
    }

    /**
     * Direct rights checks also apply to authenticated machine callers.
     *
     * @param string $right Granular operation right, or empty for summary reads.
     * @return true|\WP_Error
     */
    private static function authorize_base( string $right ) {
        if ( ! self::userless_cli() ) {
            $base = MainWP_Abilities_Util::check_rest_api_permission();
            if ( is_wp_error( $base ) ) {
                return $base;
            }
        }
        if ( '' !== $right && ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
            return new \WP_Error(
                'mainwp_insufficient_right',
                self::safe_error( 'mainwp_insufficient_right' )['message'],
                array(
                    'status' => 403,
                    'right'  => $right,
                )
            );
        }
        return true;
    }

    /**
     * Single-site and fleet access use the same scope and ownership rules.
     *
     * @param object $site Website row.
     * @return bool
     */
    private static function site_is_authorized( object $site ): bool {
        $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
        if ( 'all' !== $allowed && ! in_array( (int) $site->id, $allowed, true ) ) {
            return false;
        }
        if ( ! apply_filters( 'mainwp_check_site_access', true, (int) $site->id, null ) ) {
            return false;
        }
        return self::userless_cli() || ( MainWP_System_Utility::can_edit_website( $site ) && apply_filters( 'mainwp_currentusercan', true, 'site', (int) $site->id ) );
    }

    /**
     * Missing and denied sites share a refusal to prevent existence probes.
     *
     * @param int    $site_id Website identity.
     * @param string $right Operation right.
     * @return object|\WP_Error
     */
    public static function authorize_site( int $site_id, string $right ) {
        $base = self::authorize_base( $right );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $site = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $site ) || ! self::site_is_authorized( $site ) ) {
            /* translators: %d: site ID. */
            return new \WP_Error( 'mainwp_site_not_found', sprintf( __( 'No site found with ID %d.', 'mainwp' ), $site_id ), array( 'status' => 404 ) );
        }
        return $site;
    }

    /**
     * Fleet totals must include only sites that pass every access check.
     *
     * @param string $right Operation right, or empty for summary reads.
     * @return int[]
     */
    public static function authorized_site_ids( string $right ): array {
        $key = get_current_user_id() . ':' . (int) self::userless_cli() . ':' . (int) ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) . ':' . $right;
        if ( isset( self::$authorized_site_ids_memo[ $key ] ) ) {
            return self::$authorized_site_ids_memo[ $key ];
        }
        if ( is_wp_error( self::authorize_base( $right ) ) ) {
            self::$authorized_site_ids_memo[ $key ] = array();
            return array();
        }
        global $wpdb;
        $db         = MainWP_DB::instance();
        $sites      = $wpdb->get_results( 'SELECT wp.* FROM ' . $db->get_table_name( 'wp' ) . ' wp JOIN ' . $db->get_table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid ORDER BY wp.id' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the roster must precede caller scoping; the join retains the single-site lookup's existence rule.
        $authorized = array();
        foreach ( $sites as $site ) {
            if ( self::site_is_authorized( $site ) ) {
                $authorized[ (int) $site->id ] = (int) $site->id;
            }
        }
        $authorized                             = array_values( $authorized );
        self::$authorized_site_ids_memo[ $key ] = $authorized;
        return $authorized;
    }

    /**
     * Isolated tests can change sites and permissions within one PHP request.
     *
     * @return void
     */
    public static function reset_authorized_site_ids_memo(): void {
        self::$authorized_site_ids_memo = array();
    }

    /**
     * Entity denials disclose no more than missing lookups.
     *
     * @param string $code Not-found code.
     * @return \WP_Error
     */
    private static function not_found( string $code ): \WP_Error {
        return new \WP_Error( $code, self::safe_error( $code )['message'], array( 'status' => 404 ) );
    }

    /**
     * A task can act only within its owner and complete site scope.
     *
     * @param int    $task_id Task identity.
     * @param string $right Operation right.
     * @return object|\WP_Error
     */
    public static function authorize_task( int $task_id, string $right ) {
        $base = self::authorize_base( $right );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( ! $task || ! empty( $task->template ) || ( ! self::userless_cli() && ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $task ) ) ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        // Only the run set is checked: no task action touches a deleted site
        // or a group member added after the authorized snapshot.
        foreach ( MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ) as $site_id ) {
            if ( is_wp_error( self::authorize_site( $site_id, $right ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return $task;
    }

    /**
     * Snapshot access follows the owning site.
     *
     * @param int $snapshot_id Snapshot identity.
     * @return object|\WP_Error
     */
    public static function authorize_snapshot( int $snapshot_id ) {
        $base = self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        return ! $snapshot || is_wp_error( self::authorize_site( (int) $snapshot->site_id, 'execute_backups' ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND ) : $snapshot;
    }

    /**
     * Reservations are internal until a restore is admitted.
     *
     * @param int $restore_job_id Restore job identity.
     * @return object|\WP_Error
     */
    public static function authorize_restore_job( int $restore_job_id ) {
        $base = self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        return ! $job || 'reserved' === $job->status || is_wp_error( self::authorize_site( (int) $job->site_id, 'execute_backups' ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND ) : $job;
    }

    /**
     * Global destinations require the storage operation right.
     *
     * @param int $id Destination identity.
     * @return object|\WP_Error
     */
    public static function authorize_destination( int $id ) {
        $base = self::authorize_base( 'edit_backup_tasks' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
        return $destination ? $destination : self::not_found( MainWP_Backup_Error_Codes::ERR_DESTINATION_NOT_FOUND );
    }

    /**
     * Initiation absorbs clicks that already own a run.
     *
     * @param int    $site_id Website identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function start_site_backup( int $site_id, string $context ) {
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', esc_html__( 'The supplied input is invalid.', 'mainwp' ), array( 'status' => 400 ) );
        }
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, esc_html__( 'Native backups are not enabled.', 'mainwp' ), array( 'status' => 409 ) );
        }

        $website = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        if ( self::userless_cli() ) {
            return new \WP_Error( 'mainwp_permission_denied', esc_html__( 'Starting a backup from WP-CLI needs a Dashboard administrator. Run the command again with --user set to one.', 'mainwp' ), array( 'status' => 401 ) );
        }

        if ( MainWP_Post_Backup_Handler::site_is_disconnected( $website ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED, MainWP_Post_Backup_Handler::disconnected_site_error(), array( 'status' => 409 ) );
        }

        // A restore in flight for this site holds the child's single-backup
        // slot; refuse until it finishes so the two heavy operations cannot
        // collide on the same child.
        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, esc_html__( 'A restore is in progress for this site. Try again once it finishes.', 'mainwp' ), array( 'status' => 409 ) );
        }

        MainWP_Post_Backup_Handler::raise_backup_resource_limits();

        $task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
        if ( empty( $task ) ) {
            return new \WP_Error( 'mainwp_internal_error', esc_html__( 'Could not prepare the on-demand backup.', 'mainwp' ), array( 'status' => 500 ) );
        }

        // A run already in flight (last_run newer than completed): follow it
        // instead of resetting the run mid-flight.
        if ( (int) $task->completed < (int) $task->last_run ) {
            return (
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'running',
                )
            );
        }

        // A run that closed successfully seconds ago makes this initiate a
        // duplicate of the click that just finished (a second tab, a repeat
        // click landing as the run closed). Absorb it: point the poll at the
        // run that produced the snapshot instead of reopening it, which would
        // fire a second preflight, orphan a child task, and poison the
        // original click's freshness check into a false failure.
        $settle = MainWP_Backup_Settings::get_manual_run_settle_seconds();
        if ( $settle > 0 && '' === (string) $task->backup_errors && (int) $task->completed > time() - $settle ) {
            /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
            do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'settle_absorbed', (int) $task->last_run, (int) $task->completed );
            return (
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'queued',
                )
            );
        }

        // Kick the first step. updateRun = true resets completed_sites and
        // stamps last_run through a claim the settle window can refuse, which
        // both starts the run and makes it eligible for the
        // cron_backups_continue backstop. A false return means no concurrency
        // slot was free (or a racing initiate won the claim); the progress
        // poll retries the claim for a click whose run never opened - the
        // cron cannot, it only drives runs that were stamped open.
        $started  = time();
        $had_slot = ( false !== MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, $settle ) );

        // last_run is now stamped, so the task is eligible for a continue.
        // Kick one immediately so a tab-closed run finishes within seconds
        // instead of waiting for the 5-minute recurring backup cron.
        MainWP_Post_Backup_Handler::kick_backup_continuation();

        // Re-read the row: a successful claim stamped last_run, and a racing
        // initiate that won the claim stamped its own. Keying the response's
        // started value to the row (when it advanced) makes the poll judge
        // freshness against the run that actually owns this backup; a slot
        // miss leaves the row untouched and keeps the click's own timestamp.
        $task        = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $started_out = null !== $task && (int) $task->last_run >= $started ? (int) $task->last_run : $started;

        // The hidden manual task is normally created by the claim above, but a
        // concurrent site delete (or a failed insert) can leave the re-read
        // empty; report the failure instead of dereferencing null.
        if ( empty( $task ) ) {
            return new \WP_Error( 'mainwp_operation_failed', esc_html__( 'The backup could not be started for this site. Please reload the page and try again.', 'mainwp' ), array( 'status' => 502 ) );
        }

        return (
            array(
                'job_id'  => (int) $task->id,
                'started' => $started_out,
                'status'  => $had_slot ? 'running' : 'queued',
            )
        );
    }

    /**
     * Local completion requires an explicit stop of this run's Child task.
     *
     * An interrupted backup leaves a non-terminal task on the child that blocks
     * every new backup, and on an idle child the watchdog cron may never fire to
     * clear it. This is the Dashboard escape hatch: it tells the child to cancel
     * its known backup task, then stops treating the local manual run as
     * in-flight only after the child confirms the cancel.
     *
     * @param int $site_id Website identity.
     * @return array|\WP_Error
     */
    public static function cancel_site_backup( int $site_id ) {
        $website = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $db   = MainWP_DB_Backup::instance();
        $task = $db->get_manual_backup_task( $site_id );
        if ( ! $task || (int) $task->completed >= (int) $task->last_run ) {
            return array(
                'status' => 'not_running',
                'result' => 'not_running',
            );
        }
        // Pass only the child task id from this run's progress row: without it,
        // the child could resolve and cancel another run's active backup.
        // A creating marker is not a task id the child knows: a cancel inside
        // the create window waits for the task id instead.
        $progress = $db->get_backup_task_progress( (int) $task->id, $site_id );
        $id       = (string) ( $progress->child_backup_task_id ?? '' );
        if ( '' === $id || MainWP_Backup_Executor::is_creating_marker( $id ) ) {
            return array(
                'status' => 'busy',
                'result' => 'busy',
            );
        }
        try {
            $reply = MainWP_Backup_Executor::send_backup_task_cancel( $website, $id );
        } catch ( \Exception $e ) {
            $reply = null;
        }
        $result = null;
        if ( is_array( $reply ) && ! array_key_exists( 'error', $reply ) && is_string( $reply['result'] ?? null ) ) {
            switch ( $reply['result'] ?? '' ) {
                case 'ok':
                    $result = 'cancelled';
                    break;
                case 'already_terminal':
                    if ( 'completed' === ( $reply['phase'] ?? null ) ) {
                        $result = 'already_finished';
                    } elseif ( 'failed' === ( $reply['phase'] ?? null ) ) {
                        $result = 'already_failed';
                    }
                    break;
                case 'busy':
                    // A live runner still owns the lease mid-slice, so the child refused
                    // rather than fight it: the backup is still running. Closing the local
                    // run here would report a cancel that did not happen and leave the poll
                    // blind to a child task that keeps going.
                    $result = 'busy';
                    break;
                case 'no_active':
                    $result = 'no_active_on_site';
                    break;
                default:
                    break;
            }
        }
        if ( null === $result ) {
            return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'], array( 'status' => 502 ) );
        }
        if ( 'cancelled' === $result ) {
            // Close the local manual run so the next poll does not re-report it as
            // running; the child confirmed this cancellation. The cancelled note and
            // log line are the diagnostic trace: without them a cancelled run is
            // indistinguishable from an ordinary completion later.
            $db->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() ) );
            $db->update_backup_notices( (int) $task->id, esc_html__( 'Backup cancelled from the dashboard.', 'mainwp' ) . "\n" );
            // Drop the cancelled child task from the progress row, or the
            // progress poll keeps projecting it as running / waiting forever.
            $db->update_backup_task_progress(
                (int) $task->id,
                $site_id,
                array(
                    'child_backup_task_id' => '',
                    'child_phase'          => '',
                )
            );
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Backup cancelled from the dashboard (child task: ' . $id . ')' );
        }
        return array(
            'status' => $result,
            'result' => $result,
        );
    }

    /**
     * A stale Dashboard run can close even when its Child stop is unconfirmed.
     *
     * @param int $task_id Scheduled task identity.
     * @return array|\WP_Error Reset evidence.
     */
    public static function reset_task_run( int $task_id ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( (int) $task->completed >= (int) $task->last_run ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This task is not currently running.', 'mainwp' ), array( 'status' => 409 ) );
        }
        if ( time() - (int) $task->last_run <= MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This task is still within its normal run window and cannot be reset yet.', 'mainwp' ), array( 'status' => 409 ) );
        }
        $db          = MainWP_DB_Backup::instance();
        $cancelled   = 0;
        $uncancelled = array();
        foreach ( MainWP_Manage_Backups_Handler::resolve_reset_task_site_ids( $task ) as $site_id ) {
            $website  = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
            $progress = $db->get_backup_task_progress( (int) $task->id, (int) $site_id );
            // The child task id must come from this task's own progress row;
            // a creating marker is not an id the child knows. Without a known
            // id, the transport sends nothing and the dashboard-only close
            // is the fallback.
            try {
                $reply = $website ? MainWP_Backup_Executor::send_backup_task_cancel( $website, (string) ( $progress->child_backup_task_id ?? '' ) ) : null;
            } catch ( \Exception $e ) {
                $reply = null;
            }
            // A busy reply means a live runner still owns the lease mid-slice.
            // Only an explicit stop or a known terminal phase confirms this site's stop.
            $confirmed = is_array( $reply ) && ! array_key_exists( 'error', $reply ) && ( 'ok' === ( $reply['result'] ?? '' ) || ( 'already_terminal' === ( $reply['result'] ?? '' ) && in_array( $reply['phase'] ?? '', array( 'completed', 'failed' ), true ) ) );
            if ( $confirmed ) {
                ++$cancelled;
                MainWP_Logger::instance()->debug_for_website(
                    $website,
                    'backup',
                    'Backup cancelled on the child during a stale task reset (child task: ' . (string) $progress->child_backup_task_id . ')'
                );
                // Drop the cancelled child task from the progress row, or the
                // progress poll keeps projecting it as running / waiting forever.
                $db->update_backup_task_progress(
                    (int) $task->id,
                    (int) $site_id,
                    array(
                        'child_backup_task_id' => '',
                        'child_phase'          => '',
                    )
                );
            } else {
                $uncancelled[] = (int) $site_id;
            }
        }
        $note = esc_html__( 'Backup run reset from the dashboard after being flagged stale.', 'mainwp' );
        if ( $uncancelled ) {
            $note .= ' ' . sprintf(
                /* translators: %s: Comma-separated site ids. */
                esc_html__( 'The run could not be stopped on the child for site(s) %s; their run record was cleared on the dashboard only.', 'mainwp' ),
                implode( ', ', $uncancelled )
            );
        }
        $db->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() ) );
        $db->update_backup_notices( (int) $task->id, $note . "\n" );
        return array(
            'status'            => 'reset',
            'cancelled'         => $cancelled,
            'uncancelled'       => count( $uncancelled ),
            'sites_uncancelled' => $uncancelled,
        );
    }

    /**
     * The stored confirmation decides whether a stop can be claimed.
     *
     * @param int $restore_job_id Restore identity.
     * @return array|\WP_Error Cancellation evidence.
     */
    public static function cancel_restore( int $restore_job_id ) {
        $before = self::authorize_restore_job( $restore_job_id );
        if ( is_wp_error( $before ) ) {
            return $before;
        }
        $call = 'not called';
        if ( in_array( $before->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) && 'verifying' !== $before->status ) {
            try {
                MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $restore_job_id );
                $call = 'returned';
            } catch ( MainWP_Exception $e ) {
                // Another lane can finish after admission, so only the re-read job decides the result.
                $call = MainWP_Backup_Restore_Orchestrator::CANCEL_FINISHED_ON_SITE === $e->get_message_error_code() ? 'finished' : 'failed';
            }
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        if ( null === $job || 'reserved' === $job->status ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND );
        }
        $confirmation = $job->checkpoint['cancel_confirmation']['outcome'] ?? '';
        $result       = 'not_cancellable';
        if ( in_array( $job->status, array( 'cancelled', 'failed_permanent' ), true ) ) {
            if ( 'unconfirmed' === $confirmation ) {
                $result = 'cancel_unconfirmed';
            } elseif ( 'cancelled' === $job->status ) {
                $result = 'cancelled';
            } elseif ( 'confirmed' === $confirmation ) {
                $result = 'ended_failed';
            }
        } elseif ( in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) && 'verifying' !== $job->status ) {
            $phase = 'failed_retryable' === $job->status ? (string) ( $job->checkpoint['resume_status'] ?? 'pending' ) : $job->status;
            if ( 'verifying' === $phase ) {
                // The worker drives a verification retry on, even when it applies an armed intent.
                $result = 'not_cancellable';
            } elseif ( MainWP_Backup_Restore_Orchestrator::has_applicable_cancel_intent( $job ) || ( ! empty( $job->checkpoint['cancel_intent'] ) && in_array( $phase, array( 'pending', 'preflight', 'pre_snapshot' ), true ) ) ) {
                // An intent armed before staging survives until the worker applies it at staging.
                $result = 'cancel_pending';
            } elseif ( in_array( $call, array( 'returned', 'failed' ), true ) ) {
                // Worker progress cannot prove that a cancellation was recorded.
                return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'], array( 'status' => 502 ) );
            }
        }
        return array(
            'restore_job_id' => $restore_job_id,
            'status'         => $job->status,
            'result'         => $result,
        );
    }

    /**
     * The existing retention pass owns guarded deletion.
     *
     * @param int      $site_id Website identity.
     * @param int|null $keep Optional keep-count override.
     * @param bool     $dry_run Whether to preview deletion.
     * @return array|\WP_Error
     */
    public static function cleanup_site_backups( int $site_id, ?int $keep, bool $dry_run = false ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        // A preview must not fall through to the existing destructive pass.
        if ( $dry_run ) {
            return MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, $keep );
        }
        return MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $site_id, $keep );
    }
}
