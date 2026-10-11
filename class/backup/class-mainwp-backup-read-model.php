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
     * @param int  $site_id Website identity.
     * @param bool $with_admission Whether the caller needs full restore admission.
     * @return array[] Snapshot evidence.
     */
    public static function list_backup_snapshots( int $site_id, bool $with_admission = true ): array {
        $entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
        foreach ( $entries as &$entry ) {
            $entry = self::snapshot_evidence( $entry, $site_id, $with_admission );
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
     * @param bool  $with_admission Whether the caller needs full restore admission.
     * @return array Neutral evidence with the existing UI admission preserved.
     */
    private static function snapshot_evidence( array $entry, int $site_id, bool $with_admission = true ): array {
        $entry['catalog_restorable']     = $entry['restorable'];
        $entry['catalog_restore_reason'] = $entry['restore_reason'];
        if ( $with_admission ) {
            $admission               = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( (int) $entry['snapshot']->id, $site_id );
            $entry['restorable']     = $admission['ok'];
            $entry['restore_reason'] = $admission['ok'] ? null : self::error_value( $admission['error'], $admission['code'] );
            $entry['restore']        = $admission;
        }
        $snapshot = $entry['snapshot'];
        $checks   = array( 'files_extracted', 'db_imported', 'db_has_rows' );
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
    /**
     * Fleet pagination must follow the same complete scope checks as targeting.
     *
     * @param string   $status Task filter.
     * @param int|null $site_id Optional site filter.
     * @param int      $page Page number.
     * @param int      $per_page Page size.
     * @return array Neutral task summaries.
     */
    public static function list_tasks( string $status = 'any', ?int $site_id = null, int $page = 1, int $per_page = 50 ): array {
        $authorized = MainWP_Backup_Operations::authorized_site_ids( '' );
        $items      = array();
        foreach ( MainWP_DB_Backup::instance()->get_backup_tasks_for_user() as $task ) {
            $sites = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );
            if ( ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $task ) || array_diff( $sites, $authorized ) || ( null !== $site_id && ! in_array( $site_id, $sites, true ) ) ) {
                continue;
            }
            $summary = self::task_summary( $task );
            if ( ( 'active' === $status && $summary['paused'] ) || ( 'paused' === $status && ! $summary['paused'] ) || ( 'running' === $status && ( $summary['paused'] || ! $summary['run_open'] ) ) ) {
                continue;
            }
            $items[] = $summary;
        }
        return self::paginate( $items, $page, $per_page );
    }

    /**
     * Stored schedules can predate the currently supported values.
     *
     * @param object $task Stored row.
     * @return array Neutral summary.
     */
    private static function task_summary( object $task ): array {
        return array(
            'id'                => (int) $task->id,
            'name'              => html_entity_decode( (string) $task->name, ENT_QUOTES ),
            'type'              => (string) $task->type,
            'schedule'          => in_array( $task->schedule, array( 'daily', 'weekly', 'monthly', 'interval' ), true ) ? $task->schedule : 'legacy',
            'paused'            => ! empty( $task->paused ),
            'run_open'          => (int) $task->completed < (int) $task->last_run,
            'next_run_at'       => (int) $task->next_run,
            'last_run_at'       => (int) $task->last_run,
            'last_completed_at' => (int) $task->completed,
            'site_count'        => count( MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ) ),
            'destination_count' => count( self::stored_ids( $task->destinations ?? '[]', true ) ),
        );
    }

    /**
     * Legacy selections are stored as CSV or JSON rather than typed arrays.
     *
     * @param string $raw Stored selection.
     * @param bool   $json Whether the selection uses JSON.
     * @return int[]
     */
    private static function stored_ids( string $raw, bool $json = false ): array {
        return array_values( array_filter( array_map( 'intval', $json ? ( json_decode( $raw, true ) ?? array() ) : explode( ',', $raw ) ) ) );
    }

    /**
     * Detail reads retain stored settings without invoking save-time rules.
     *
     * @param object $task Authorized task row.
     * @return array Neutral configuration.
     */
    public static function get_task( object $task ): array {
        $destinations = array();
        $selected     = self::stored_ids( $task->destinations ?? '[]', true );
        foreach ( MainWP_Backup_Storage_Destinations::instance()->list_destinations() as $destination ) {
            if ( in_array( (int) $destination->id, $selected, true ) ) {
                $destinations[] = array(
                    'id'       => (int) $destination->id,
                    'name'     => (string) $destination->name,
                    'provider' => (string) $destination->provider,
                );
            }
        }
        $exclude = html_entity_decode( (string) $task->exclude, ENT_QUOTES );
        return array(
            'task'             => self::task_summary( $task ),
            'schedule_detail'  => array(
                'interval_hours' => 'interval' === $task->schedule && (int) $task->interval_hours > 0 ? (int) $task->interval_hours : null,
                'time_of_day'    => in_array( $task->schedule, array( 'daily', 'weekly', 'monthly' ), true ) && '' !== $task->time_of_day ? (string) $task->time_of_day : null,
                'day_of_week'    => 'weekly' === $task->schedule ? (int) $task->day_of_week : null,
                'day_of_month'   => 'monthly' === $task->schedule ? (int) $task->day_of_month : null,
            ),
            'scope'            => array(
                'site_ids'            => self::stored_ids( (string) $task->sites ),
                'group_ids'           => self::stored_ids( (string) $task->groups ),
                'authorized_site_ids' => null === $task->authorized_sites ? null : self::stored_ids( (string) $task->authorized_sites ),
            ),
            'destinations'     => $destinations,
            'retention'        => array(
                'count'               => (int) $task->retention_count,
                'days'                => (int) $task->retention_days,
                'storage_mb'          => (int) $task->retention_storage_mb,
                'delete_cloud_copies' => ! empty( $task->delete_cloud_copies ),
                'max_chain_length'    => (int) $task->max_chain_length,
            ),
            'archive_format'   => in_array( $task->archiveFormat, array( 'site', 'global', 'zip', 'tar', 'tar.gz', 'tar.bz2' ), true ) ? $task->archiveFormat : 'legacy',
            'exclude'          => '' === $exclude ? array() : preg_split( '/\r\n|\r|\n/', $exclude ),
            'exclude_flags'    => array(
                'backup_archives' => ! empty( $task->excludebackup ),
                'cache'           => ! empty( $task->excludecache ),
                'non_wp_folders'  => ! empty( $task->excludenonwp ),
                'zip_archives'    => ! empty( $task->excludezip ),
            ),
            'archive_tuning'   => array(
                'max_file_descriptors_override' => ! empty( $task->maximumFileDescriptorsOverride ),
                'max_file_descriptors_auto'     => ! empty( $task->maximumFileDescriptorsAuto ),
                'max_file_descriptors'          => (int) $task->maximumFileDescriptors,
                'load_files_before_zip'         => ! empty( $task->loadFilesBeforeZip ),
                'zip_force_override'            => ! empty( $task->zip_force_override ),
            ),
            'owner_user_id'    => (int) $task->userid,
            'last_run_summary' => array(
                'errors'  => self::error_list( (string) $task->backup_errors ),
                'notices' => self::error_list( (string) ( $task->backup_notices ?? '' ) ),
            ),
        );
    }

    /**
     * Empty stored text is absence of an error, not an unclassified failure.
     *
     * @param string $text Stored errors or notices.
     * @return array[] Neutral errors.
     */
    private static function error_list( string $text ): array {
        return '' === $text ? array() : array( self::error_value( $text ) );
    }

    /**
     * Run polling uses the shared progress evidence without driving a claim.
     *
     * @param object $task Authorized task row.
     * @return array Neutral run details.
     */
    public static function task_run_details( object $task ): array {
        $run   = self::get_task_run( $task, (int) $task->last_run );
        $sites = array();
        foreach ( $run['sites'] as $site ) {
            if ( 0 === (int) $task->last_run ) {
                // get_task_run() marks every site of a closed run failed; a task that never ran has no failures.
                $site['status'] = 'pending';
            }
            $site['site_id'] = $site['id'];
            $site['phase']   = self::known_phase( (string) $site['phase'] );
            $site['error']   = '' === $site['error']['source_text'] ? null : $site['error'];
            if ( null !== $site['snapshot'] ) {
                $site['snapshot']['created_at'] = $site['snapshot']['created'];
            }
            $sites[] = $site;
        }
        return array(
            'task_id'      => (int) $task->id,
            'status'       => (int) $task->last_run > 0 ? $run['status'] : 'never_run',
            'run_open'     => $run['run_open'],
            'stalled'      => $run['run_open'] && time() - (int) $task->last_run >= MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS,
            'started_at'   => (int) $task->last_run,
            'completed_at' => $run['run_open'] ? 0 : (int) $task->completed,
            'sites'        => $sites,
            'errors'       => self::error_list( $run['errors']['source_text'] ),
            'notices'      => self::error_list( $run['notices']['source_text'] ),
        );
    }

    /**
     * Newer Child phases remain visible without breaking the known enum.
     *
     * @param string $phase Stored phase.
     * @return string|null
     */
    private static function known_phase( string $phase ): ?string {
        return '' === $phase ? null : ( null === MainWP_Backup_Task_Phase::tryFrom( $phase ) ? 'unknown' : $phase );
    }

    /**
     * One site's state includes on-demand evidence and authorized scheduled runs.
     *
     * @param int $site_id Authorized site.
     * @param int $since Start handle.
     * @return array Neutral progress.
     */
    public static function site_backup_state( int $site_id, int $since = 0 ): array {
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( ! $task || ( $since > 0 && (int) $task->last_run < $since ) ) {
            $progress = array( 'status' => $since > 0 ? 'not_started' : 'idle' );
        } elseif ( 0 === (int) $task->last_run && empty( $task->paused ) ) {
            $progress = array( 'status' => 'idle' );
        } else {
            $progress = self::get_site_backup_progress( $site_id, $since );
        }
        $progress          = array_merge(
            array(
                'status'          => 'idle',
                'phase'           => null,
                'label'           => '',
                'percent'         => null,
                'files_processed' => null,
                'files_total'     => null,
                'bytes_done'      => null,
                'bytes_expected'  => null,
                'snapshot_id'     => null,
                'error'           => null,
                'notice'          => null,
            ),
            $progress
        );
        $progress['phase'] = self::known_phase( (string) $progress['phase'] );
        $scheduled         = array();
        foreach ( MainWP_DB_Backup::instance()->get_backup_tasks_to_complete() as $scheduled_task ) {
            if ( ! empty( $scheduled_task->template ) || is_wp_error( MainWP_Backup_Operations::authorize_task( (int) $scheduled_task->id, 'execute_backups' ) ) || ! in_array( $site_id, MainWP_Manage_Backups_Handler::resolve_task_run_sites( $scheduled_task ), true ) ) {
                continue;
            }
            $row = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $scheduled_task->id, $site_id );
            if ( ! $row ) {
                continue;
            }
            $detail      = MainWP_Post_Backup_Handler::build_task_progress_response( $row );
            $scheduled[] = array(
                'task_id'   => (int) $scheduled_task->id,
                'task_name' => html_entity_decode( (string) $scheduled_task->name, ENT_QUOTES ),
                'phase'     => self::known_phase( (string) ( $detail['phase'] ?? '' ) ),
                'percent'   => $detail['percent'] ?? null,
            );
        }
        return array(
            'site_id'        => $site_id,
            'on_demand'      => $progress,
            'scheduled_runs' => $scheduled,
        );
    }

    /**
     * Snapshot pagination excludes pruned evidence unless requested.
     *
     * @param int  $site_id Authorized site.
     * @param bool $include_pruned Include retained metadata for pruned copies.
     * @param int  $page Page number.
     * @param int  $per_page Page size.
     * @return array Neutral snapshot list.
     */
    public static function query_backup_snapshots( int $site_id, bool $include_pruned = false, int $page = 1, int $per_page = 50 ): array {
        $items = array();
        foreach ( self::list_backup_snapshots( $site_id ) as $entry ) {
            if ( $include_pruned || 'pruned' !== $entry['snapshot']->status ) {
                $items[] = self::snapshot_details_from_entry( $entry );
            }
        }
        $active = MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id );
        $task   = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        return array_merge(
            self::paginate( $items, $page, $per_page ),
            array(
                'site_id'                  => $site_id,
                'site_disconnected'        => MainWP_Backup_Restore_Orchestrator::site_is_disconnected( MainWP_DB::instance()->get_website_by_id( $site_id ) ),
                'active_restore_job_id'    => $active ? (int) $active->id : null,
                'on_demand_backup_running' => $task && (int) $task->completed < (int) $task->last_run,
            )
        );
    }

    /**
     * Detail and list reads use the same full admission evidence.
     *
     * @param int $snapshot_id Authorized snapshot.
     * @return array|\WP_Error
     */
    public static function snapshot_details( int $snapshot_id ) {
        $entry = self::get_backup_snapshot( $snapshot_id );
        return is_wp_error( $entry ) ? $entry : self::snapshot_details_from_entry( $entry );
    }

    /**
     * Only recorded evidence can support a snapshot's recovery claims.
     *
     * @param array $entry Neutral catalog entry.
     * @return array Neutral snapshot details.
     */
    private static function snapshot_details_from_entry( array $entry ): array {
        $snapshot     = $entry['snapshot'];
        $task         = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $snapshot->task_id );
        $offsite      = '' === $snapshot->offsite_state ? 'none' : (string) $snapshot->offsite_state;
        $summary      = array(
            'id'             => (int) $snapshot->id,
            'site_id'        => (int) $snapshot->site_id,
            'task_id'        => (int) $snapshot->task_id,
            'origin'         => ! $task ? 'deleted_task' : ( (int) $task->manual_site_id === (int) $snapshot->site_id ? 'on_demand' : 'scheduled_task' ),
            'type'           => (string) $snapshot->type,
            'parent_id'      => $snapshot->parent_id,
            'created_at'     => (int) $snapshot->created,
            'status'         => (string) $snapshot->status,
            'evidence'       => array(
                'recorded_by'       => 'dashboard_after_download',
                'archive_integrity' => 'transfer_hash_only',
                'skipped_files'     => 'not_reported',
            ),
            'size_bytes'     => array_sum(
                array_map(
                    static function ( $archive ) {
                        return (int) ( $archive['size'] ?? 0 ); },
                    $snapshot->archives
                )
            ),
            'encrypted'      => ! empty( $snapshot->encryption ),
            'source'         => $entry['source'],
            'restorable'     => $entry['restorable'],
            'restore_reason' => $entry['restore_reason'],
            'offsite'        => array(
                'state'       => $offsite,
                'evidence'    => 'verified' === $offsite ? 'remote_size_match' : 'none',
                'verified_at' => (int) $snapshot->verified_at,
            ),
            'test_restore'   => $entry['test_restore'],
        );
        $destinations = array();
        foreach ( MainWP_Backup_Storage_Destinations::instance()->list_destinations() as $destination ) {
            $destinations[ (int) $destination->id ] = (string) $destination->name;
        }
        $uploads = array();
        foreach ( $entry['uploads'] as $upload ) {
            $uploads[] = array(
                'destination_id'   => (int) $upload->destination_id,
                'destination_name' => $destinations[ (int) $upload->destination_id ] ?? null,
                'provider'         => (string) $upload->provider,
                'status'           => (string) $upload->status,
                'updated_at'       => (int) $upload->updated,
                'last_error'       => '' === $upload->last_error['source_text'] ? null : $upload->last_error,
            );
        }
        $archives = array();
        foreach ( $snapshot->archives as $archive ) {
            $archives[] = array(
                'file'       => basename( str_replace( '\\', '/', (string) ( $archive['file'] ?? '' ) ) ),
                'size_bytes' => (int) ( $archive['size'] ?? 0 ),
                'role'       => (string) ( $archive['role'] ?? '' ),
            );
        }
        return array(
            'snapshot'             => $summary,
            'uploads'              => $uploads,
            'archives'             => $archives,
            'manifest_present'     => ! empty( $snapshot->manifest_path ) && file_exists( $snapshot->manifest_path ),
            'chain_ids'            => array_map(
                static function ( $link ) {
                    return (int) $link->id; },
                MainWP_Backup_Snapshots::instance()->get_chain( (int) $snapshot->id )
            ),
            'has_woocommerce_meta' => ! empty( $snapshot->woo_meta ),
        );
    }

    /**
     * Restore list totals and pagination must use the fully authorized fleet.
     *
     * @param int|null $site_id Optional authorized site.
     * @param string   $outcome Outcome filter.
     * @param int      $page Page number.
     * @param int      $per_page Page size.
     * @return array Neutral restore summaries.
     */
    public static function query_restore_jobs( ?int $site_id = null, string $outcome = 'any', int $page = 1, int $per_page = 50 ): array {
        $ids = null === $site_id ? MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) : array( $site_id );
        if ( empty( $ids ) ) {
            return self::paginate( array(), $page, $per_page );
        }
        global $wpdb;
        // nosemgrep: AIK_tainted-sql-string -- The table name is fixed and every id is cast to int.
        $rows  = $wpdb->get_col( 'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ") AND status <> 'reserved' ORDER BY created DESC, id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- trusted table and authorized integer IDs.
        $items = array();
        foreach ( $rows as $id ) {
            $detail = self::restore_job_details( (int) $id );
            if ( ! is_wp_error( $detail ) && ( 'any' === $outcome || $outcome === $detail['job']['outcome'] ) ) {
                $items[] = $detail['job'];
            }
        }
        return self::paginate( $items, $page, $per_page );
    }

    /**
     * Checkpoint evidence distinguishes cancellation from confirmed recovery.
     *
     * @param int $restore_job_id Authorized restore identity.
     * @return array|\WP_Error
     */
    public static function restore_job_details( int $restore_job_id ) {
        $detail = self::get_restore_job( $restore_job_id );
        if ( is_wp_error( $detail ) ) {
            return $detail;
        }
        $job      = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        $cp       = $job->checkpoint;
        $terminal = in_array( $job->status, array( 'completed', 'cancelled', 'failed_permanent' ), true );
        $safety   = 'pending';
        if ( ! empty( $cp['is_rollback'] ) || empty( $cp['options']['pre_snapshot'] ) ) {
            $safety = 'not_requested';
        } elseif ( ! empty( $cp['pre_snapshot_pruned'] ) ) {
            $safety = 'pruned';
        } elseif ( ! empty( $cp['pre_snapshot_artifacts'] ) ) {
            $safety = 'taken';
        } elseif ( $terminal || in_array( $job->status, array( 'staging', 'transferring', 'running', 'verifying' ), true ) ) {
            $safety = 'failed';
        }
        $reasons = $detail['partial_state_reasons'];
        $risk    = 'none';
        if ( 'completed' !== $job->status && ! empty( $job->child_task_id ) ) {
            if ( $terminal || in_array( $job->status, array( 'running', 'verifying', 'failed_retryable' ), true ) ) {
                $risk      = $terminal ? 'possible' : 'unknown';
                $reasons[] = 'child_apply_started';
                if ( 'db' !== $detail['sub_type'] ) {
                    $reasons[] = 'no_files_rollback';
                    if ( 'full' === $detail['sub_type'] ) {
                        $reasons[] = 'db_applied_after_files';
                    }
                }
            }
        }
        $verification = $detail['verification'];
        $child_report = $verification;
        unset( $child_report['site_responds'] );
        return array(
            'job'                      => array(
                'id'                  => (int) $job->id,
                'site_id'             => (int) $job->site_id,
                'snapshot_id'         => (int) $job->snapshot_id,
                'status'              => $detail['status'],
                'outcome'             => $detail['outcome'],
                'restore_type'        => '' !== $detail['sub_type'] ? $detail['sub_type'] : 'full',
                'is_rollback'         => $detail['is_rollback'],
                'rollback_of'         => ! empty( $cp['rollback_of'] ) ? (int) $cp['rollback_of'] : null,
                'safety_backup'       => $safety,
                'rollback_available'  => $detail['has_rollback'],
                'cancel_requested'    => $detail['cancel_requested'],
                'cancel_confirmation' => $detail['cancel_confirmation'],
                'error'               => '' === $detail['error']['source_text'] && '' === $detail['error_code'] ? null : $detail['error'],
                'attempt_count'       => $detail['attempt_count'],
                'created_at'          => (int) $job->created,
                'updated_at'          => (int) $job->updated,
            ),
            'progress'                 => array(
                'artifact_index'    => $detail['artifact_index'],
                'artifact_total'    => $detail['artifact_total'],
                'bytes_transferred' => $detail['bytes_transferred'] ?? null,
                'bytes_total'       => $detail['bytes_total'] ?? null,
            ),
            'next_retry_at'            => $detail['next_retry_at'],
            'verification'             => array(
                'child_report_present'       => ! empty( $child_report ),
                'site_responds'              => $verification['site_responds'] ?? null,
                'site_responds_rule'         => 'http_200_to_499',
                'woo_preserve_applied'       => isset( $verification['woo_preserve'] ) ? ! empty( $verification['woo_preserve'] ) : null,
                'woo_orphan_customer_orders' => isset( $verification['woo_orphans']['orphan_customer_orders'] ) ? (int) $verification['woo_orphans']['orphan_customer_orders'] : null,
            ),
            'partial_state_risk'       => $risk,
            'partial_state_reasons'    => $reasons,
            'files_rollback_available' => false,
        );
    }

    /**
     * Totals describe the filtered set before any page is sliced.
     *
     * @param array $items Filtered rows.
     * @param int   $page Page number.
     * @param int   $per_page Page size.
     * @return array
     */
    private static function paginate( array $items, int $page, int $per_page ): array {
        return array(
            'items'    => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => count( $items ),
            'page'     => $page,
            'per_page' => $per_page,
        );
    }
    /**
     * Fleet totals and pagination must use the complete authorized site set.
     *
     * @param int   $threshold_days Freshness window.
     * @param bool  $only_stale Whether to return stale rows only.
     * @param array $site_ids_or_domains Optional fleet filter.
     * @param int   $page Page number.
     * @param int   $per_page Page size.
     * @return array Neutral status evidence.
     */
    public static function get_backup_status( int $threshold_days = 7, bool $only_stale = false, array $site_ids_or_domains = array(), int $page = 1, int $per_page = 50 ): array {
        $authorized = MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' );
        $requested  = array();
        if ( ! empty( $site_ids_or_domains ) ) {
            foreach ( MainWP_Abilities_Util::resolve_sites( $site_ids_or_domains )['sites'] as $site ) {
                if ( in_array( (int) $site->id, $authorized, true ) ) {
                    $requested[] = (int) $site->id;
                }
            }
            $requested  = array_values( array_unique( $requested ) );
            $authorized = array_values( array_intersect( $authorized, $requested ) );
        }
        $snapshots = MainWP_Backup_Snapshots::instance()->get_latest_per_site( $authorized );
        $attempts  = array();
        $legacy    = array();
        foreach ( MainWP_DB_Backup::instance()->get_backup_tasks_for_user() as $task ) {
            $started = (int) $task->last_run;
            if ( $started <= 0 ) {
                continue;
            }
            $completed = json_decode( (string) $task->completed_sites, true );
            foreach ( array_intersect( MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ), $authorized ) as $id ) {
                $attempts[ $id ] = max( $attempts[ $id ] ?? 0, $started );
                $progress        = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $id );
                $error           = $progress && (int) $progress->dtsFetched >= $started ? (string) $progress->last_error : (string) $task->backup_errors;
                // The runner marks completion after errors too; an archive alone cannot override an error mark.
                if ( empty( $completed[ $id ] ) || '' !== $error ) {
                    continue;
                }
                if ( MainWP_Backup_Executor::latest_backup_archive_mtime( $id ) >= $started ) {
                    $legacy[ $id ] = max( $legacy[ $id ] ?? 0, $started );
                }
            }
        }
        $totals = array(
            'sites'     => count( $authorized ),
            'stale'     => 0,
            'never'     => 0,
            'by_health' => array(
                'protected' => 0,
                'at_risk'   => 0,
                'critical'  => 0,
                'unknown'   => 0,
            ),
        );
        $items  = array();
        $now    = time();
        foreach ( $authorized as $id ) {
            $site    = MainWP_DB::instance()->get_website_by_id( $id );
            $options = MainWP_DB::instance()->get_website_options_array( $site, array( 'primary_lasttime_backup' ) );
            $child   = max( 0, (int) ( $options['primary_lasttime_backup'] ?? 0 ) );
            $last    = max( $child, $legacy[ $id ] ?? 0 );
            $source  = $last > 0 ? ( $child >= ( $legacy[ $id ] ?? 0 ) ? 'child_reported' : 'legacy_task' ) : 'none';
            $latest  = $snapshots[ $id ] ?? null;
            $summary = null;
            $health  = MainWP_Backup_Snapshots::classify_backup_health( $latest, $now );
            if ( $latest ) {
                $details = self::snapshot_details( (int) $latest->id );
                $summary = $details['snapshot'];
                if ( (int) $latest->created >= $last ) {
                    $last   = (int) $latest->created;
                    $source = 'native_snapshot';
                }
            }
            $stale = 0 === $last || $last < $now - $threshold_days * DAY_IN_SECONDS;
            ++$totals['by_health'][ $health ];
            $totals['stale'] += (int) $stale;
            $totals['never'] += (int) ( 0 === $last );
            if ( ! $only_stale || $stale ) {
                $items[] = array(
                    'site_id'         => $id,
                    'url'             => (string) $site->url,
                    'name'            => html_entity_decode( (string) $site->name, ENT_QUOTES ),
                    'last_backup'     => $last,
                    'last_attempted'  => $attempts[ $id ] ?? 0,
                    'source'          => $source,
                    'is_stale'        => $stale,
                    'latest_snapshot' => $summary,
                    'health'          => $health,
                    'update_gate'     => MainWP_Backup_Operations::update_gate_for_site( $site ),
                );
            }
        }
        $paged = self::paginate( $items, $page, $per_page );
        return array(
            'items'                  => $paged['items'],
            'totals'                 => $totals,
            'page'                   => $page,
            'per_page'               => $per_page,
            'filters'                => array(
                'threshold_days' => $threshold_days,
                'only_stale'     => $only_stale,
                'site_ids'       => $requested,
            ),
            'native_backups_enabled' => MainWP_Backup_Settings::is_native_backups_enabled(),
            'update_gate_policy'     => self::update_gate_policy(),
            'note'                   => empty( $paged['items'] ) ? __( 'No sites matched the applied filters.', 'mainwp' ) : '',
        );
    }

    /**
     * History pagination must exclude denied sites before counting rows.
     *
     * @param array $filters Validated history filters.
     * @param int   $page Page number.
     * @param int   $per_page Page size.
     * @return array Neutral history evidence.
     */
    public static function list_history( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
        $result = MainWP_Backup_Run_History::query( $filters, $page, $per_page, MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
        $items  = array();
        foreach ( $result['items'] as $row ) {
            $task = (int) $row['task_id'] > 0 ? MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $row['task_id'] ) : null;
            $site = MainWP_DB::instance()->get_website_by_id( (int) $row['site_id'] );
            $code = (string) $row['error_code'];
            if ( '' === $code && in_array( $row['outcome'], array( 'cancelled', 'reset', 'incomplete' ), true ) ) {
                $code = 'mainwp_backup_run_' . $row['outcome'];
            }
            $failed  = in_array( $row['outcome'], array( 'failed', 'cancelled', 'reset', 'incomplete' ), true );
            $items[] = array(
                'id'                => (int) $row['id'],
                'task_id'           => (int) $row['task_id'] > 0 ? (int) $row['task_id'] : null,
                'task_name'         => $task ? html_entity_decode( (string) $task->name, ENT_QUOTES ) : null,
                'site_id'           => (int) $row['site_id'],
                'site_name'         => $site ? html_entity_decode( (string) $site->name, ENT_QUOTES ) : null,
                'trigger'           => $row['trigger_source'],
                'initiator_user_id' => (int) $row['initiator_user_id'],
                'outcome'           => $row['outcome'],
                'started_at'        => (int) $row['started'],
                'finished_at'       => (int) $row['finished'],
                'error'             => $failed ? array( 'code' => $code ) : null,
                'snapshot_recorded' => ! empty( $row['snapshot_id'] ),
                'snapshot_id'       => ! empty( $row['snapshot_id'] ) ? (int) $row['snapshot_id'] : null,
                'restore_job_id'    => ! empty( $row['restore_job_id'] ) ? (int) $row['restore_job_id'] : null,
                'ref'               => $row['ref'],
            );
        }
        return array(
            'items'          => $items,
            'total'          => $result['total'],
            'page'           => $page,
            'per_page'       => $per_page,
            'retention_days' => 90,
        );
    }

    /**
     * Lists need decrypted public locations and the same unreadable-credentials flag as details.
     *
     * @param string $provider Provider filter.
     * @param int    $page Page number.
     * @param int    $per_page Page size.
     * @return array Public destination page.
     */
    public static function list_destinations( string $provider = 'any', int $page = 1, int $per_page = 50 ): array {
        $items = array();
        foreach ( MainWP_Backup_Storage_Destinations::instance()->list_destinations() as $row ) {
            if ( 'any' === $provider || $row->provider === $provider ) {
                $items[] = self::get_destination( (int) $row->id );
            }
        }
        return self::paginate( $items, $page, $per_page );
    }

    /**
     * An allowlist also excludes identifiers and paths the edit form retains.
     *
     * @param int $id Destination identity.
     * @return array|\WP_Error Public metadata.
     */
    public static function get_destination( int $id ) {
        $row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
        if ( ! $row ) {
            return new \WP_Error( 'mainwp_backup_destination_not_found', '', array( 'status' => 404 ) );
        }
        $settings = is_array( $row->settings ) ? $row->settings : array();
        $location = array();
        foreach ( array_intersect_key( $settings, array_flip( array( 'bucket', 'region', 'endpoint', 'path_style', 'host', 'port' ) ) ) as $key => $value ) {
            if ( 'path_style' === $key ) {
                $location[ $key ] = (bool) $value;
            } elseif ( 'port' === $key ) {
                // The SFTP form saves a blank port and the provider falls back to 22, so an unusable value is absent, not 0.
                $port = (int) $value;
                if ( $port >= 1 && $port <= 65535 ) {
                    $location[ $key ] = $port;
                }
            } elseif ( '' !== (string) $value ) {
                $location[ $key ] = (string) $value;
            }
        }
        $count = 0;
        global $wpdb;
        // Hidden on-demand tasks (template = 1) also store destinations, but no task list shows them.
        $tasks = $wpdb->get_col( 'SELECT destinations FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup' ) . ' WHERE template = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- only stored destination references are counted, using a fixed table identity.
        foreach ( $tasks as $ids ) {
            $count += (int) in_array( $id, self::stored_ids( (string) $ids, true ), true );
        }
        return array(
            'id'                  => (int) $row->id,
            'name'                => (string) $row->name,
            'provider'            => (string) $row->provider,
            'is_default'          => ! empty( $row->is_default ),
            'location'            => $location,
            'credentials_invalid' => ! empty( $row->credentials_invalid ),
            'task_count'          => $count,
            'created_at'          => (int) $row->created,
            'updated_at'          => (int) $row->updated,
        );
    }

    /**
     * Read contracts preserve settings stored above the machine write bounds.
     *
     * @return array Public settings and boolean encryption flags.
     */
    public static function get_settings(): array {
        $encryption = MainWP_Backup_Encryption::instance();
        return array(
            'native_backups_enabled'    => MainWP_Backup_Settings::is_native_backups_enabled(),
            'primary_backup_method'     => (string) get_option( 'mainwp_primaryBackup', '' ),
            'on_demand_retention_count' => MainWP_Backup_Settings::get_on_demand_retention_count(),
            'max_concurrent_backups'    => MainWP_Backup_Settings::get_max_concurrent_backups(),
            'diagnostics_enabled'       => MainWP_Backup_Diagnostics::is_enabled(),
            'update_gate'               => self::update_gate_policy(),
            'encryption'                => array(
                'enabled'                 => $encryption->is_enabled_setting(),
                'recovery_passphrase_set' => ! empty( $encryption->get_recovery_blob() ),
            ),
        );
    }

    /**
     * Status and settings must describe the same enforcing policy.
     *
     * @return array Gate policy.
     */
    private static function update_gate_policy(): array {
        return array(
            'enabled'         => 1 === (int) get_option( 'mainwp_backup_before_upgrade', 0 ),
            'max_age_days'    => MainWP_Updates_Overview::get_backup_before_upgrade_days(),
            'require_offsite' => (bool) get_option( 'mainwp_backup_before_upgrade_require_offsite', 0 ),
        );
    }
}
