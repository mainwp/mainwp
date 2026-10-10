<?php
/**
 * Backup reads retain source evidence without driving work or choosing a transport.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Class MainWP_Backup_Read_Model. */
class MainWP_Backup_Read_Model { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Readers retain source evidence for separate transport serializers.
     *
     * @param string $source_text Stored text, preserved for the Dashboard.
     * @param string $code Explicit code when the record carries one.
     * @return array Neutral error evidence.
     */
    private static function error_value( string $source_text, string $code = '' ): array {
        if ( '' === $code && preg_match( '/^\[(mainwp_[a-z0-9_]+)\]/', $source_text, $match ) ) {
            $code = $match[1];
        }
        return array(
            'code'        => '' !== $code ? $code : MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR,
            'source_text' => $source_text,
        );
    }

    /**
     * WordPress deletes expired transients during ordinary reads.
     *
     * @param string $key Completed-run cache key.
     * @return mixed Unexpired stored evidence, or false.
     */
    private static function saved_run( string $key ) {
        if ( wp_using_ext_object_cache() || wp_installing() ) {
            return wp_cache_get( $key, 'transient' );
        }
        $timeout = get_option( '_transient_timeout_' . $key );
        return false !== $timeout && (int) $timeout < time() ? false : get_option( '_transient_' . $key );
    }

    /**
     * Run evidence comes from the owned row or its saved completed copy.
     *
     * @param object $task Stored task row.
     * @param int    $started Owned run timestamp.
     * @param int    $click Click timestamp.
     * @return array Run evidence.
     */
    public static function get_task_run( object $task, int $started = 0, int $click = 0 ): array {
        $saved = false;
        if ( $started > 0 && (int) $task->last_run !== $started ) {
            $saved = self::saved_run( 'mainwp_backup_run_' . (int) $task->id . '_' . $started );
            if ( ! is_array( $saved ) || ! isset( $saved['task'] ) || ! is_object( $saved['task'] ) || ! isset( $saved['progress'], $saved['snapshots'] ) ) {
                return array(
                    'error' => esc_html__( 'The result for this backup run is no longer available. Reload the page to see the latest run.', 'mainwp' ),
                    'code'  => 'run_not_found',
                );
            }
            $task = $saved['task'];

        }
        $owns_run    = $started > 0 && (int) $task->last_run === $started;
        $queued      = 0 === $started && $click > 0 && $click <= time();
        $open        = $owns_run && (int) $task->completed < $started;
        $cutoff      = $started;
        $sites       = array();
        $stale_sites = array();
        $complete    = 0;
        $errors      = $owns_run ? self::error_value( (string) $task->backup_errors ) : self::error_value( '' );
        $notices     = $owns_run ? self::error_value( (string) ( $task->backup_notices ?? '' ) ) : self::error_value( '' );
        foreach ( MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task, $stale_sites ) as $site_id ) {
            $website  = MainWP_DB::instance()->get_website_by_id( $site_id );
            $progress = $saved ? ( $saved['progress'][ $site_id ] ?? null ) : MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
            if ( ! $owns_run || $queued || ( $progress && (int) $progress->dtsFetched < max( $cutoff, (int) $task->last_run ) ) ) {
                $progress = null;
            }
            $detail   = MainWP_Post_Backup_Handler::build_task_progress_response( $progress );
            $snapshot = $saved ? ( $saved['snapshots'][ $site_id ] ?? null ) : MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
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
                'error'        => self::error_value( 'failed' === $status && $progress ? (string) $progress->last_error : '', 'failed' === $status && $progress ? (string) ( $progress->fetchResult['error_code'] ?? '' ) : '' ),
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
            $status = count( $sites ) === $complete && '' === MainWP_Post_Backup_Handler::humanize_backup_errors( $errors['source_text'] ) ? 'completed' : 'completed_with_errors';
        }
        return array(
            'status'      => $status,
            'run_state'   => $saved ? 'closed' : ( $owns_run ? ( $open ? 'open' : 'closed' ) : 'unowned' ),
            'stale_sites' => $stale_sites,
            'started'     => $started,
            'click'       => $click,
            'run_open'    => (int) $task->completed < (int) $task->last_run,
            'errors'      => $errors,
            'notices'     => $notices,
            'sites'       => $sites,
        );
    }

    /**
     * Snapshot freshness decides completion independently of the Child phase.
     *
     * @param int $site_id Website identity.
     * @param int $since Click's freshness cutoff.
     * @return array Progress evidence.
     */
    public static function get_site_backup_progress( int $site_id, int $since = 0 ): array {
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( empty( $task ) ) {
            return array( 'error' => self::error_value( __( 'No on-demand backup is running for this site.', 'mainwp' ) ) );
        }
        if ( ! empty( $task->paused ) ) {
            $notice = (string) ( $task->backup_notices ?? '' );
            return (
                array(
                    'status' => 'failed',
                    'error'  => '' !== $notice ? self::error_value( $notice ) : self::error_value( __( 'This task is paused. Resume it before running it.', 'mainwp' ) ),
                )
            );
        }

        if ( $task && (int) $task->completed < (int) $task->last_run ) {
            // Reuse the shared progress shape (label, percent, counters, bytes)
            // and pin the status to running: snapshot freshness, checked below,
            // is the authoritative completion gate, not the child phase.
            $progress           = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
            $response           = MainWP_Post_Backup_Handler::build_task_progress_response( $progress );
            $response['status'] = 'running';
            return ( $response );
        }

        // The run is closed: success when a snapshot fresh for THIS poll's
        // click landed, otherwise surface the recorded failure. The client
        // sends the started value the initiate handed it as `since`, so a
        // later re-init bumping last_run cannot poison the freshness check
        // of the click that produced the snapshot; without it (a tab on
        // older JavaScript) the comparison stays keyed to last_run.
        $errors = $task ? (string) $task->backup_errors : '';
        $latest = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
        $cutoff = $since > 0 ? $since : (int) $task->last_run;
        $fresh  = null !== $latest && (int) $latest->created >= $cutoff;

        // backup_errors clears at every run open, so it only ever describes
        // the run tied to the CURRENT last_run. The two surfaces below need
        // different ownership questions answered from that fact.
        //
        // The success gate asks whether the errors belong to the run that
        // produced the fresh snapshot: only while last_run has not moved
        // past the snapshot. A later run's failure must not poison the
        // click whose snapshot landed.
        //
        // The failure surface asks whether the errors belong to THIS
        // click's run: they do once last_run caught up to the click's
        // since (or on older JavaScript that sends none). Keying this to
        // the snapshot instead silently disowned the current run's real
        // error whenever the site's only snapshot was an old one - a
        // child's disk-full message surfaced as the generic no-snapshot
        // line exactly because of that.
        $errors_snapshot_run = null !== $latest && (int) $task->last_run <= (int) $latest->created;
        $errors_click_own    = ( 0 === $since || (int) $task->last_run >= $since );

        if ( $fresh && ( '' === $errors || ! $errors_snapshot_run ) ) {
            $response = array(
                'status'      => 'completed',
                'snapshot_id' => (int) $latest->id,
            );
            // Advisory run notes (like the large-site ZIP auto-switch) ride
            // along so the page shows them with the success status instead of
            // mistaking them for a failure.
            $notices = $task ? (string) ( $task->backup_notices ?? '' ) : '';
            if ( '' !== $notices ) {
                $response['notice'] = self::error_value( $notices );
            }
            return ( $response );
        }

        return (
            array(
                'status' => 'failed',
                'error'  => '' !== $errors && $errors_click_own ? self::error_value( $errors ) : self::error_value( __( 'The on-demand backup did not produce a snapshot.', 'mainwp' ) ),
            )
        );
    }

    /**
     * The stored checkpoint is the source of restore progress.
     *
     * @param int $restore_job_id Restore job identity.
     * @return array|\WP_Error Restore evidence.
     */
    public static function get_restore_job( int $restore_job_id ) {
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        if ( null === $job || 'reserved' === $job->status ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND, MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND )['message'], array( 'status' => 404 ) );
        }
        $confirmation = (array) ( $job->checkpoint['cancel_confirmation'] ?? array() );
        $terminal     = in_array( $job->status, array( 'completed', 'cancelled', 'failed_permanent' ), true );
        $outcome      = 'in_progress';
        if ( 'completed' === $job->status ) {
            $outcome = 'completed';
        } elseif ( $terminal ) {
            $outcome = 'unconfirmed' === ( $confirmation['outcome'] ?? '' ) ? 'cancel_unconfirmed' : ( 'cancelled' === $job->status ? 'cancelled' : 'failed' );
        }
        $reasons  = 'cancel_unconfirmed' === $outcome ? array( 'cancel_unconfirmed' ) : array();
        $transfer = isset( $job->checkpoint['transfer'] ) ? (array) $job->checkpoint['transfer'] : array();
        $plan     = isset( $job->checkpoint['plan'] ) ? (array) $job->checkpoint['plan'] : array();

        return (
            array_merge(
                array(
                    'status'                => (string) $job->status,
                    'outcome'               => $outcome,
                    'cancel_confirmation'   => (string) ( $confirmation['outcome'] ?? 'not_requested' ),
                    'error'                 => self::error_value( (string) $job->last_error, (string) ( $job->checkpoint['last_error_code'] ?? '' ) ),
                    'partial_state_reasons' => $reasons,
                    'last_error'            => self::error_value( (string) $job->last_error, (string) ( $job->checkpoint['last_error_code'] ?? '' ) ),
                    'error_code'            => isset( $job->checkpoint['last_error_code'] ) ? (string) $job->checkpoint['last_error_code'] : '',
                    'attempt_count'         => (int) $job->attempt_count,
                    'next_retry_at'         => (int) $job->next_retry_at,
                    'next_retry_label'      => (int) $job->next_retry_at > 0 ? MainWP_Utility::format_time( MainWP_Utility::get_timestamp( (int) $job->next_retry_at ) ) : '',
                    'artifact_index'        => isset( $transfer['artifact_index'] ) ? (int) $transfer['artifact_index'] : 0,
                    'artifact_total'        => count( $plan ),
                    'has_rollback'          => MainWP_Backup_Restore_Orchestrator::instance()->has_rollback_source( $job ),
                    'cancel_requested'      => ! empty( $job->checkpoint['cancel_intent'] ),
                    'sub_type'              => (string) ( $job->checkpoint['sub_type'] ?? '' ),
                    'is_rollback'           => ! empty( $job->checkpoint['is_rollback'] ),
                    'verification'          => isset( $job->checkpoint['verification'] ) ? $job->checkpoint['verification'] : array(),
                ),
                self::transfer_byte_progress( $plan, $transfer )
            )
        );
    }

    /**
     * Byte-level transfer progress for the poll payload.
     *
     * Completed artifacts count in full, the in-flight one counts its
     * confirmed offset, adopt-only entries never cross the wire and stay
     * out of both totals. Plans whose archive metadata predates sizes
     * report nothing rather than a misleading 0/0, so the keys are only
     * present when a real total is known.
     *
     * @param array $plan     Checkpoint plan entries.
     * @param array $transfer Checkpoint transfer cursor.
     *
     * @return array Empty, or { bytes_transferred: int, bytes_total: int }.
     */
    private static function transfer_byte_progress( array $plan, array $transfer ): array {
        $index       = isset( $transfer['artifact_index'] ) ? (int) $transfer['artifact_index'] : 0;
        $offset      = isset( $transfer['offset'] ) ? (int) $transfer['offset'] : 0;
        $total       = 0;
        $transferred = 0;

        foreach ( array_values( $plan ) as $i => $entry ) {
            if ( ! empty( $entry['adopt_only'] ) ) {
                continue;
            }
            $size   = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
            $total += $size;
            if ( $i < $index ) {
                $transferred += $size;
            } elseif ( $i === $index ) {
                $transferred += min( $offset, $size );
            }
        }

        if ( $total <= 0 ) {
            return array();
        }

        return array(
            'bytes_transferred' => $transferred,
            'bytes_total'       => $total,
        );
    }

    /**
     * Restore admission can refuse a copy that the cheap list flag accepts.
     *
     * @param int $site_id Website identity.
     * @return array[] Snapshot evidence.
     */
    public static function list_backup_snapshots( int $site_id ): array {
        $entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
        foreach ( $entries as &$entry ) {
            $entry = self::snapshot_evidence( $entry, $site_id );
        }
        unset( $entry );
        return $entries;
    }

    /**
     * Detail reads and restore previews share admission evidence.
     *
     * @param int $snapshot_id Snapshot identity.
     * @return array|\WP_Error Snapshot evidence and restore admission.
     */
    public static function get_backup_snapshot( int $snapshot_id ) {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( null === $snapshot ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND, MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND )['message'], array( 'status' => 404 ) );
        }
        foreach ( MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( (int) $snapshot->site_id ) as $entry ) {
            if ( (int) $entry['snapshot']->id === $snapshot_id ) {
                return self::snapshot_evidence( $entry, (int) $snapshot->site_id );
            }
        }
        return array();
    }

    /**
     * Keep the existing list flag separate from full restore admission.
     *
     * @param array $entry Catalog row.
     * @param int   $site_id Expected owner site.
     * @return array Neutral evidence with the existing UI admission preserved.
     */
    private static function snapshot_evidence( array $entry, int $site_id ): array {
        $admission                       = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( (int) $entry['snapshot']->id, $site_id );
        $entry['catalog_restorable']     = $entry['restorable'];
        $entry['catalog_restore_reason'] = $entry['restore_reason'];
        $entry['restorable']             = $admission['ok'];
        $entry['restore_reason']         = $admission['ok'] ? null : self::error_value( $admission['error'], $admission['code'] );
        $entry['restore']                = $admission;
        $snapshot                        = $entry['snapshot'];
        $checks                          = array( 'files_extracted', 'db_imported', 'db_has_rows' );
        if ( 'files' === $snapshot->type ) {
            $checks = array( 'files_extracted' );
        } elseif ( 'db' === $snapshot->type ) {
            $checks = array( 'db_imported', 'db_has_rows' );
        }
        $entry['test_restore'] = array(
            'state'        => '' === $snapshot->test_restore_state ? 'never' : (string) $snapshot->test_restore_state,
            'attempted_at' => empty( $snapshot->test_restore_at ) ? null : (int) $snapshot->test_restore_at,
            'result_at'    => empty( $snapshot->test_restore_result_at ) ? null : (int) $snapshot->test_restore_result_at,
            'checks'       => $checks,
            'scope'        => 'dashboard_sandbox',
        );
        $entry['uploads']      = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $entry['snapshot']->id );
        foreach ( $entry['uploads'] as $job ) {
            $job->last_error = self::error_value( (string) $job->last_error );
        }
        return $entry;
    }

    /**
     * Recoverable restores survive a page reload.
     *
     * @param int $site_id Website identity.
     * @return array Active and recoverable restore jobs for page reloads.
     */
    public static function list_restore_jobs( int $site_id ): array {
        global $wpdb;
        $active_job = MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id );
        // An available safety backup ranks first; a successor rollback owns its source.
        $failed_jobs = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existing restore job table.
            $wpdb->prepare(
                'SELECT failed.id, failed.snapshot_id, failed.site_id, failed.status, failed.last_error, failed.checkpoint, failed.created FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' failed WHERE failed.site_id = %d AND failed.status IN ( %s, %s ) ORDER BY ( failed.checkpoint LIKE %s AND NOT EXISTS ( SELECT 1 FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' successor WHERE successor.site_id = failed.site_id AND successor.id > failed.id AND successor.checkpoint REGEXP CONCAT( %s, failed.id, %s ) ) ) DESC, failed.id DESC LIMIT 20', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                $site_id,
                MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
                MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
                '%' . $wpdb->esc_like( '"pre_snapshot_artifacts":[{' ) . '%',
                '"rollback_of"[[:space:]]*:[[:space:]]*',
                '[[:space:]]*[,}]'
            )
        );
        $failed_jobs = is_array( $failed_jobs ) ? $failed_jobs : array();
        // A completed restore keeps its safety backup until the next one
        // completes, so its Roll Back has to survive a reload too. Normally
        // only the newest completed restore still has one.
        $completed_jobs = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existing restore job table.
            $wpdb->prepare(
                'SELECT id, snapshot_id, site_id, status, last_error, checkpoint, created FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND status = %s AND checkpoint LIKE %s AND checkpoint NOT LIKE %s ORDER BY id DESC LIMIT 5', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                $site_id,
                MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED,
                '%' . $wpdb->esc_like( '"pre_snapshot_artifacts":[{' ) . '%',
                '%' . $wpdb->esc_like( '"pre_snapshot_pruned":true' ) . '%'
            )
        );
        foreach ( is_array( $completed_jobs ) ? $completed_jobs : array() as $completed_job ) {
            $completed_job->checkpoint = json_decode( $completed_job->checkpoint, true );
            if ( is_array( $completed_job->checkpoint ) && MainWP_Backup_Restore_Orchestrator::instance()->has_rollback_source( $completed_job ) ) {
                $failed_jobs[] = $completed_job;
            }
        }
        usort(
            $failed_jobs,
            static function ( $a, $b ) {
                return (int) $b->id - (int) $a->id;
            }
        );
        $failed_rows = array();
        foreach ( $failed_jobs as $failed_job ) {
            if ( ! is_array( $failed_job->checkpoint ) ) {
                $failed_job->checkpoint = json_decode( (string) $failed_job->checkpoint, true );
            }
            $failed_rows[] = array(
                'job_id'       => (int) $failed_job->id,
                'snapshot_id'  => (int) $failed_job->snapshot_id,
                'status'       => (string) $failed_job->status,
                'date_label'   => (int) $failed_job->created > 0 ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( (int) $failed_job->created ) ) : '',
                'last_error'   => self::error_value( (string) $failed_job->last_error, (string) ( $failed_job->checkpoint['last_error_code'] ?? '' ) ),
                'error_code'   => (string) ( $failed_job->checkpoint['last_error_code'] ?? '' ),
                'has_rollback' => MainWP_Backup_Restore_Orchestrator::instance()->has_rollback_source( $failed_job ),
                'is_rollback'  => ! empty( $failed_job->checkpoint['is_rollback'] ),
            );
        }
        return array(
            'active_restore_job_id' => null !== $active_job ? (int) $active_job->id : 0,
            'failed_restore_jobs'   => $failed_rows,
        );
    }
}
