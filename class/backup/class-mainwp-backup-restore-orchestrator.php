<?php
/**
 * MainWP Backup Restore Orchestrator - the dashboard side of a restore.
 *
 * A restore job moves through dashboard phases on budgeted cron slices:
 * an optional pre-restore safety backup runs to completion as a normal
 * child backup task (its artifacts stay on the child - they are the
 * file rollback source and adopt with zero transfer); staging makes
 * every plan artifact a verified local file; the child restore task is
 * created and the artifacts stream over as hash-verified chunks; the
 * child task is then driven one slice per tick to its terminal phase;
 * and a final external reachability check gates completion.
 *
 * The child enforces one active task per site, which is why the
 * pre-restore backup runs and finishes before the restore task is
 * created. The dashboard mirrors that with one active restore job per
 * site.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Restore_Orchestrator
 */
class MainWP_Backup_Restore_Orchestrator { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /** The replay spends the first of seven attempts, so six reads span 270 s, past the Child's 180 s lease, before a first slice counts as stuck. */
    private const RECONCILE_INTERVAL_SECONDS = 45;

    /**
     * Cron slice budget, seconds. Below the upload driver's 240 so both
     * fit one continuation tick with headroom.
     */
    const DEFAULT_BUDGET_SECONDS = 200;

    /** No further work belongs to this caller. */
    const TERMINAL_OUTCOME_TERMINAL = 'terminal';

    /** A durable decision or child confirmation must be retried. */
    const TERMINAL_OUTCOME_DEFERRED = 'deferred';

    /** The normal completion path may continue. */
    const TERMINAL_OUTCOME_DRIVE_ON = 'drive_on';

    /**
     * The drive-on path restores the admission checkpoint, so callers need a
     * code to tell a finished restore from a cancel that failed to record.
     */
    const CANCEL_FINISHED_ON_SITE = 'mainwp_restore_finished_on_site';

    /** A live or changed verification phase requires a fresh user decision. */
    const CANCEL_CHECKING_CHANGED = 'mainwp_restore_checking_changed';

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Restore_Orchestrator|null
     */
    private static $instance = null;

    /**
     * The lease token for the slice currently driven by
     * process_pending_restore_jobs(), threaded into every job-store write
     * so a worker whose lease was reclaimed is fenced out. Null outside a
     * slice: direct run_job() callers hold no lease and write unfenced.
     *
     * @var string|null
     */
    private $lock_token = null;

    /**
     * The lease duration of the current slice, used by renewals.
     *
     * @var int
     */
    private $lease_seconds = 0;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Restore_Orchestrator
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Whether the Dashboard's last contact with a site failed.
     *
     * A site with sync errors cannot take a backup or a restore: the request
     * would fail partway, after a safety backup or a transfer had already
     * spent its time. The UI turns the actions off and the endpoints refuse.
     *
     * @param object|null $website Website row.
     *
     * @return bool
     */
    public static function site_is_disconnected( $website ): bool {
        return ! empty( $website ) && '' !== trim( (string) ( $website->sync_errors ?? '' ) );
    }

    /**
     * Whether a restore must be explicitly confirmed before it runs.
     *
     * A restore that rewrites the database - anything but a files-only
     * snapshot - overwrites live data, so the server requires the same
     * consent the modal collects and a direct AJAX call cannot skip it. The
     * caller surfaces the WooCommerce/backup-age reason; this only decides
     * whether the gate fires.
     *
     * @param object|null $snapshot            Snapshot row being restored.
     * @param bool        $confirm_destructive Whether the request carried consent.
     *
     * @return bool True when consent is required but absent.
     */
    public static function restore_needs_confirmation( $snapshot, $confirm_destructive ) {
        $destructive = null !== $snapshot && 'files' !== (string) $snapshot->type;
        return $destructive && ! $confirm_destructive;
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Create a restore job for a snapshot.
     *
     * @param int   $site_id     Child site id.
     * @param int   $snapshot_id Snapshot to restore.
     * @param array $options     Restore flags, including confirm_destructive consent.
     *
     * @throws MainWP_Exception When the restore cannot start.
     *
     * @return int Job id.
     */
    public function initiate_restore( int $site_id, int $snapshot_id, array $options = array() ): int {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        if ( ! MainWP_Install::backup_restore_job_checkpoint_rev_ready() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RESTORE_SCHEMA_REPAIR_PENDING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        if ( self::site_is_disconnected( $website ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        // Re-derive destructive risk server-side. The modal collects consent,
        // but a direct AJAX call can omit it, so a database-bearing restore is
        // refused until confirm_destructive is set. The response carries
        // the reason (WooCommerce, backup age) for the modal and CLI to explain.
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( null !== $snapshot && (int) $snapshot->site_id === $site_id && self::restore_needs_confirmation( $snapshot, ! empty( $options['confirm_destructive'] ) ) ) {
            $refusal = new MainWP_Exception( esc_html__( 'Confirmation is required for this action.', 'mainwp' ), null, 'mainwp_confirmation_required' );
            $refusal->set_data(
                array(
                    'site_has_woocommerce' => MainWP_Utility::site_has_woocommerce( $site_id ),
                    'age_days'             => max( 0, (int) floor( ( time() - (int) $snapshot->created ) / DAY_IN_SECONDS ) ),
                )
            );
            throw $refusal; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped at construction.
        }

        // Order preservation applies only to a database-bearing restore; the
        // backup's created time bounds the customer capture on the child.
        $options['preserve_orders'] = ! empty( $options['preserve_orders'] ) && null !== $snapshot && (int) $snapshot->site_id === $site_id && 'files' !== (string) $snapshot->type;
        $options['woo_order_since'] = $options['preserve_orders'] ? (int) $snapshot->created : 0;

        // A preserve-orders request the child's apply-phase gate is certain
        // to refuse gets refused here, before any job, pre-snapshot, or
        // transfer spends hours on it. Best effort: any doubt proceeds and
        // the apply-phase gate stays the authoritative backstop.
        if ( $options['preserve_orders'] ) {
            $refusal = $this->woo_preserve_refusal( $website, $snapshot );
            if ( null !== $refusal ) {
                throw MainWP_Backup_Error_Codes::exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
        }

        $this->assert_no_open_site_backup( $site_id );
        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before starting another.', 'mainwp' ), null, MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped and the code is a trusted constant.
        }

        if ( ! MainWP_Backup_Monitor::child_supports_restore_create_idempotency( $website ) ) {
            // The stored flag goes stale on exactly the update this refusal asks
            // for, so re-negotiate once before taking Restore away.
            MainWP_Backup_Monitor::refresh_negotiated_capabilities( $website );
            if ( MainWP_Backup_Monitor::child_requires_restore_create_idempotency_update( $website ) ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RESTORE_CHILD_UPDATE_REQUIRED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
        }

        // Hold the site's restore slot before resolving the chain: Clean Up
        // deletes a snapshot only while it holds the same slot, so the files
        // resolved here cannot be removed before this job exists.
        $store       = MainWP_Backup_Restore_Job_Store::instance();
        $reservation = $this->reserve_site_for( $site_id, esc_html__( 'A restore is already running for this site. Wait for it to finish before starting another.', 'mainwp' ) );
        try {
            $job_id      = $this->admit_restore( $reservation, $site_id, $snapshot_id, $options );
            $reservation = 0;
        } finally {
            if ( $reservation > 0 ) {
                $store->release_reservation( $reservation );
            }
        }

        $this->schedule_continuation();
        return $job_id;
    }

    /**
     * Resolve the chain and turn the held reservation into the restore job.
     *
     * @param int   $reservation Reservation id holding the site.
     * @param int   $site_id     Child site id.
     * @param int   $snapshot_id Snapshot to restore.
     * @param array $options     Restore options.
     *
     * @throws MainWP_Exception When the restore cannot start.
     *
     * @return int Job id.
     */
    private function admit_restore( int $reservation, int $site_id, int $snapshot_id, array $options ): int {
        $resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id, $site_id );
        if ( ! $resolved['ok'] ) {
            throw new MainWP_Exception( esc_html( $resolved['error'] ), null, $resolved['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- catalog codes are trusted identifiers; the message is escaped.
        }

        $checkpoint = array(
            'restore_id' => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'   => $resolved['sub_type'],
            'plan'       => $resolved['plan'],
            'options'    => array(
                'pre_snapshot'    => ! empty( $options['pre_snapshot'] ),
                'file_rollback'   => ! empty( $options['file_rollback'] ),
                'preserve_orders' => ! empty( $options['preserve_orders'] ),
                'woo_order_since' => isset( $options['woo_order_since'] ) ? (int) $options['woo_order_since'] : 0,
            ),
            'transfer'   => array(),
        );

        $job_id = MainWP_Backup_Restore_Job_Store::instance()->promote_reservation( $reservation, $snapshot_id, $checkpoint );
        if ( $job_id <= 0 ) {
            throw new MainWP_Exception( esc_html__( 'The restore job could not be recorded.', 'mainwp' ) );
        }
        return $job_id;
    }

    /**
     * Take the site's restore slot or refuse.
     *
     * @param int    $site_id Child site id.
     * @param string $running Refusal when an active restore holds the site.
     *
     * @throws MainWP_Exception When the site's slot is taken.
     *
     * @return int Reservation id.
     */
    private function reserve_site_for( int $site_id, string $running ): int {
        $store       = MainWP_Backup_Restore_Job_Store::instance();
        $reservation = $store->reserve_site( $site_id );
        if ( $reservation > 0 ) {
            return $reservation;
        }
        if ( null !== $store->get_active_job_for_site( $site_id ) ) {
            // A concurrent submit won the slot for this site.
            throw new MainWP_Exception( $running, null, MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- callers pass escaped strings.
        }
        throw new MainWP_Exception( esc_html__( 'Another backup action is working on this site. Try again in a minute.', 'mainwp' ), null, MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped and the code is a trusted constant.
    }

    /**
     * An open on-demand backup already owns the Child's backup lane.
     *
     * @param int $site_id Target site identity.
     * @throws MainWP_Exception When the site's backup run has not closed.
     */
    private function assert_no_open_site_backup( int $site_id ): void {
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( $task && (int) $task->completed < (int) $task->last_run ) {
            throw new MainWP_Exception( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ), null, MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Whether a preserve-orders restore is certain to be refused by the
     * child's apply-phase gate, answered at initiate time so the refusal
     * costs a second instead of the whole transfer.
     *
     * Best effort with a hard no-false-refusals invariant. The two live
     * option checks mirror the child gate exactly (absent options refuse
     * the same way the gate would). The backup-content checks run only on
     * unambiguous meta: a database-bearing single snapshot that carries
     * woo_meta - an incremental chain's post-replay woocommerce_db_version
     * depends on which dump in the chain last wrote the options table,
     * which meta cannot answer. Any doubt (unreachable child, an older
     * child without the callable, a malformed response, absent meta)
     * defers to the apply-phase gate, which stays authoritative.
     *
     * @param object      $website  Website object.
     * @param object|null $snapshot Leaf snapshot row being restored.
     *
     * @return string|null Error code to refuse with, or null to proceed.
     */
    public function woo_preserve_refusal( object $website, ?object $snapshot ): ?string {
        try {
            $preflight = MainWP_Connect::fetch_url_authed( $website, 'restore_preflight', array(), false, false, false );
        } catch ( \Throwable $e ) {
            return null; // Unreachable: the apply-phase gate backstops.
        }
        if ( ! is_array( $preflight ) || isset( $preflight['error'] ) || ! array_key_exists( 'woo_hpos_enabled', $preflight ) ) {
            return null; // Older child or malformed response: defer.
        }

        if ( empty( $preflight['woo_hpos_enabled'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }
        if ( ! empty( $preflight['woo_sync_enabled'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_SYNC_MODE;
        }

        if ( null === $snapshot || ! in_array( (string) $snapshot->type, array( 'full', 'db' ), true ) ) {
            return null;
        }
        $meta = isset( $snapshot->woo_meta ) && is_array( $snapshot->woo_meta ) ? $snapshot->woo_meta : array();
        if ( empty( $meta ) ) {
            return null;
        }

        if ( empty( $meta['has_wc_orders'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }

        $backup_ver = isset( $meta['woocommerce_db_version'] ) ? (string) $meta['woocommerce_db_version'] : '';
        $live_ver   = isset( $preflight['woo_db_version'] ) ? (string) $preflight['woo_db_version'] : '';
        if ( '' !== $backup_ver && '' !== $live_ver && $backup_ver !== $live_ver ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_VERSION_SKEW;
        }

        return null;
    }

    /**
     * Whether this job still owns an available safety backup.
     *
     * A successor rollback owns the retry action even when it fails. Its
     * checkpoint link also prevents a completed rollback from being offered again.
     * A completed restore keeps its safety backup on the site until a later
     * restore of the same site takes a new one or completes, so the user can
     * still undo a restore of the wrong snapshot. A completed rollback has used
     * its safety backup. A later completion supersedes it even before its prune
     * is confirmed, and restores completed before the prune existed never
     * recorded one. A safety backup the child confirmed removing, or one a
     * prune may have removed without the removal being recorded, is gone
     * whatever the restore's outcome.
     *
     * @param object $job Restore job with a decoded checkpoint.
     *
     * @return bool Whether the safety backup is available to this job.
     */
    public function has_rollback_source( object $job ): bool {
        if ( empty( $job->checkpoint['pre_snapshot_artifacts'] ) || ! empty( $job->checkpoint['pre_snapshot_pruned'] ) || ! empty( $job->checkpoint['pre_snapshot_pruning'] ) ) {
            return false;
        }
        if ( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED === $job->status && ( ! empty( $job->checkpoint['is_rollback'] ) || $this->superseded_by_later_completion( $job ) ) ) {
            return false;
        }

        return ! $this->rollback_started_for( $job );
    }

    /**
     * Whether a later rollback names this job as its source.
     *
     * @param object $job Restore job.
     *
     * @return bool
     */
    private function rollback_started_for( object $job ): bool {
        global $wpdb;
        $checkpoints = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existing restore job table.
            $wpdb->prepare(
                'SELECT checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND id > %d AND checkpoint LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                (int) $job->site_id,
                (int) $job->id,
                '%' . $wpdb->esc_like( '"rollback_of":' ) . '%'
            )
        );
        foreach ( $checkpoints as $checkpoint_json ) {
            $checkpoint = json_decode( $checkpoint_json, true );
            if ( (int) ( $checkpoint['rollback_of'] ?? 0 ) === (int) $job->id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a later restore or rollback of the same site has completed.
     *
     * @param object $job Restore job.
     *
     * @return bool
     */
    private function superseded_by_later_completion( object $job ): bool {
        global $wpdb;
        return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existing restore job table.
            $wpdb->prepare(
                'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND id > %d AND status = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                (int) $job->site_id,
                (int) $job->id,
                MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED
            )
        );
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all refusals throw MainWP_Exception.
    /**
     * Start a rollback restore from a completed pre-restore safety
     * backup: its artifacts are still on the child and adopt with zero
     * transfer.
     *
     * @param object $failed_job Finished (failed, cancelled, or completed)
     *                           restore job carrying pre_snapshot_artifacts
     *                           in its checkpoint.
     *
     * @throws MainWP_Exception When the job has no rollback source.
     *
     * @return int New job id.
     */
    public function initiate_rollback( object $failed_job ): int {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        if ( ! MainWP_Install::backup_restore_job_checkpoint_rev_ready() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RESTORE_SCHEMA_REPAIR_PENDING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        $website = MainWP_DB::instance()->get_website_by_id( (int) $failed_job->site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }
        if ( self::site_is_disconnected( $website ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        $this->assert_rollback_status( $failed_job );
        $this->assert_no_open_site_backup( (int) $failed_job->site_id );

        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( (int) $failed_job->site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before rolling back.', 'mainwp' ), null, MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped and the code is a trusted constant.
        }

        // A later restore can retire this source between the caller's read
        // and now, and it does so only while holding the site's slot. Take
        // the slot, then judge the source from the stored row.
        $store       = MainWP_Backup_Restore_Job_Store::instance();
        $reservation = $this->reserve_site_for( (int) $failed_job->site_id, esc_html__( 'A restore is already running for this site. Wait for it to finish before rolling back.', 'mainwp' ) );
        try {
            $source = $store->get_job( (int) $failed_job->id );
            if ( null === $source || (int) $source->site_id !== (int) $failed_job->site_id ) {
                throw new MainWP_Exception( esc_html__( 'Restore job not found.', 'mainwp' ) );
            }
            $job_id      = $this->admit_rollback( $reservation, $source );
            $reservation = 0;
        } finally {
            if ( $reservation > 0 ) {
                $store->release_reservation( $reservation );
            }
        }

        $this->schedule_continuation();
        return $job_id;
    }

    /**
     * Refuse a rollback of a restore that has not finished.
     *
     * @param object $job Restore job.
     *
     * @throws MainWP_Exception When the job is still active.
     */
    private function assert_rollback_status( object $job ): void {
        if ( ! in_array( $job->status, array( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED ), true ) ) {
            throw new MainWP_Exception( esc_html__( 'Only finished restores can be rolled back.', 'mainwp' ), '', 'mainwp_restore_rollback_status' );
        }
    }

    /**
     * Validate the stored source and turn the held reservation into the
     * rollback job.
     *
     * @param int    $reservation Reservation id holding the site.
     * @param object $failed_job  Source restore job, freshly read.
     *
     * @throws MainWP_Exception When the job has no rollback source.
     *
     * @return int New job id.
     */
    private function admit_rollback( int $reservation, object $failed_job ): int {
        $this->assert_rollback_status( $failed_job );

        $artifacts = isset( $failed_job->checkpoint['pre_snapshot_artifacts'] ) ? (array) $failed_job->checkpoint['pre_snapshot_artifacts'] : array();
        if ( ! $this->has_rollback_source( $failed_job ) ) {
            if ( empty( $artifacts ) ) {
                throw new MainWP_Exception( esc_html__( 'No safety backup was taken before this restore, so there is nothing to roll back to.', 'mainwp' ), null, 'mainwp_restore_rollback_status' );
            }
            // A completed rollback also retires its source, so ask about the
            // rollback first or its own success would read as a replacement.
            if ( $this->rollback_started_for( $failed_job ) ) {
                throw new MainWP_Exception( esc_html__( 'A rollback has already been started for this restore, so it cannot be rolled back again.', 'mainwp' ), null, 'mainwp_restore_rollback_status' );
            }
            if ( ! empty( $failed_job->checkpoint['pre_snapshot_pruned'] ) || ! empty( $failed_job->checkpoint['pre_snapshot_pruning'] ) || ( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED === $failed_job->status && empty( $failed_job->checkpoint['is_rollback'] ) && $this->superseded_by_later_completion( $failed_job ) ) ) {
                throw new MainWP_Exception( esc_html__( 'The safety backup from this restore was replaced by a later restore, so there is nothing to roll back to.', 'mainwp' ), null, 'mainwp_restore_rollback_status' );
            }
            throw new MainWP_Exception( esc_html__( 'A rollback has already been started for this restore, so it cannot be rolled back again.', 'mainwp' ), null, 'mainwp_restore_rollback_status' );
        }

        $plan = array();
        foreach ( $artifacts as $artifact ) {
            $plan[] = array(
                'role'        => isset( $artifact['role'] ) ? (string) $artifact['role'] : 'full',
                'file'        => (string) $artifact['file'],
                'size'        => isset( $artifact['size'] ) ? (int) $artifact['size'] : 0,
                'sha256'      => '',
                'snapshot_id' => 0,
                'source'      => 'adopt',
                'local_path'  => '',
                'remote_key'  => '',
                'adopt_only'  => true,
            );
        }

        $checkpoint = array(
            'restore_id'             => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'               => 'full',
            'is_rollback'            => true,
            'rollback_of'            => (int) $failed_job->id,
            'pre_snapshot_artifacts' => $artifacts,
            'plan'                   => $plan,
            'options'                => array(
                'pre_snapshot'  => false,
                'file_rollback' => false,
            ),
            'transfer'               => array(),
        );

        $job_id = MainWP_Backup_Restore_Job_Store::instance()->promote_reservation( $reservation, (int) $failed_job->snapshot_id, $checkpoint );
        if ( $job_id <= 0 ) {
            throw new MainWP_Exception( esc_html__( 'The rollback job could not be recorded.', 'mainwp' ) );
        }
        return $job_id;
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Cancel a restore job, deferring a busy child to the cron lane.
     *
     * Admission ends when verification begins. The cancellation matrix owns
     * child confirmation, durable decisions, cleanup and safety-backup release.
     *
     * @param int $job_id Job id.
     *
     * @throws MainWP_Exception When the job is past the cancel window,
     *                          or another lane already finished it.
     *
     * @return string 'stopped' after ending verification checks,
     *                'cancelled' for a cancelled or permanently failed job,
     *                or 'cancel_pending' when the decision must be retried.
     */
    public function cancel_job( int $job_id ): string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $job   = $store->get_job( $job_id );
        if ( null === $job ) {
            throw new MainWP_Exception( esc_html__( 'Restore job not found.', 'mainwp' ) );
        }

        $action = self::resolve_cancel_action( $job );
        if ( 'none' === $action ) {
            if ( MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED === $job->status ) {
                throw new MainWP_Exception( esc_html__( 'The restore is already cancelled.', 'mainwp' ) );
            }
            throw new MainWP_Exception( esc_html__( 'This restore has already finished on the site and can no longer be cancelled. If it failed, you can roll it back instead.', 'mainwp' ) );
        }
        // Every cancel write names the revision column, so nothing could be recorded.
        if ( ! MainWP_Install::backup_restore_job_checkpoint_rev_ready() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RESTORE_SCHEMA_REPAIR_PENDING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $outcome = $this->record_terminal_failure( $job, 'stop_checking' === $action ? (string) $job->last_error : null, (string) ( $job->checkpoint['last_error_code'] ?? '' ), null, null, '', $action );
        if ( 'stopped' === $outcome ) {
            return 'stopped';
        }
        if ( 'checking_changed' === $outcome || ( 'stop_checking' === $action && self::TERMINAL_OUTCOME_TERMINAL !== $outcome ) ) {
            throw new MainWP_Exception( esc_html__( 'The Dashboard is checking the site right now, or the restore status just changed. Try again in a moment.', 'mainwp' ), null, self::CANCEL_CHECKING_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the final argument is an error code.
        }
        if ( 'stop_checking' === $action ) {
            throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ), null, self::CANCEL_FINISHED_ON_SITE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the final argument is an error code.
        }

        if ( self::TERMINAL_OUTCOME_DEFERRED === $outcome ) {
            return 'cancel_pending';
        }
        if ( self::TERMINAL_OUTCOME_TERMINAL === $outcome ) {
            $fresh = $store->get_job( $job_id );
            if ( null !== $fresh && in_array( $fresh->status, array( $store::STATUS_CANCELLED, $store::STATUS_FAILED_PERMANENT ), true ) ) {
                return 'cancelled';
            }
            if ( null === $fresh || ! in_array( $fresh->status, array( $store::STATUS_VERIFYING, $store::STATUS_COMPLETED ), true ) ) {
                throw new MainWP_Exception( esc_html__( 'The restore reached its final state while the cancellation was confirmed and no longer needs cancelling.', 'mainwp' ) );
            }
        }
        throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ), null, self::CANCEL_FINISHED_ON_SITE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the final argument is an error code.
    }

    /**
     * Verification retries follow Child completion, so cancelling them only ends the checks.
     *
     * @param object $job Persisted restore job with a decoded checkpoint.
     * @return string cancel, stop_checking, or none.
     */
    public static function resolve_cancel_action( object $job ): string {
        $store = MainWP_Backup_Restore_Job_Store::class;
        if ( ! in_array( $job->status, $store::active_statuses(), true ) || $store::STATUS_VERIFYING === $job->status ) {
            return 'none';
        }
        return $store::STATUS_FAILED_RETRYABLE === $job->status && ( $job->checkpoint['resume_status'] ?? '' ) === $store::STATUS_VERIFYING ? 'stop_checking' : 'cancel';
    }

    /**
     * A stored confirmation can outlive the intent or the phase that could apply it.
     *
     * @param object $job Persisted restore job, including its checkpoint.
     * @return bool Whether the worker applies its cancel intent before dispatch.
     */
    public static function has_applicable_cancel_intent( object $job ): bool {
        $phase = $job->status;
        if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $phase ) {
            $phase = isset( $job->checkpoint['resume_status'] ) ? (string) $job->checkpoint['resume_status'] : MainWP_Backup_Restore_Job_Store::STATUS_PENDING;
        }
        return ! empty( $job->checkpoint['cancel_intent'] ) && ( in_array(
            $phase,
            array(
                MainWP_Backup_Restore_Job_Store::STATUS_STAGING,
                MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
                MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
            ),
            true
        ) || ! empty( $job->checkpoint['terminal_failure'] ) );
    }

    /**
     * Cancel and release the safety-backup handle this job owns, if any.
     *
     * Every caller runs this only after the job's own cancellation is
     * durable, so the handle is never given up by a job that is still
     * active. The option is per site: another lane's handle must stay
     * intact, and an absent handle means the task is terminal or already
     * released. Release runs in a finally so an unreachable child cannot
     * leave the handle set, and the whole thing is best effort: a site
     * that cannot be reached must not turn a completed cancellation into
     * a failure the caller reports.
     *
     * @param object $job Job row.
     *
     * @return void
     */
    private function cancel_owned_pre_snapshot_handle( object $job ): void {
        try {
            $website = $this->website( $job );
            // A handle this restore did not create belongs to another lane;
            // an absent handle means the task is terminal or already released.
            $raw    = MainWP_DB::instance()->get_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
            $handle = json_decode( (string) $raw, true );
            $owner  = (string) $job->id;
            $owned  = is_array( $handle ) && isset( $handle['task_id'], $handle['owner'] ) && is_scalar( $handle['task_id'] ) && '' !== (string) $handle['task_id'] && is_scalar( $handle['owner'] ) && $owner === (string) $handle['owner'];
            if ( $owned ) {
                $task_id = (string) $handle['task_id'];
                try {
                    MainWP_Backup_Executor::send_backup_task_cancel( $website, (string) $task_id );
                } finally {
                    $this->release_pre_snapshot_handle( $website, $task_id, $owner );
                }
            }
        } catch ( \Throwable $e ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its safety backup: %s', (int) $job->id, $e->getMessage() ), (int) $job->site_id, 0 );
        }
    }

    /**
     * Release an owned safety-backup handle so later restores cannot adopt it.
     *
     * The option is per site: a newer backup can replace it while a child call
     * is in flight, and another lane may own the same task id.
     *
     * @param object $website Website object.
     * @param string $task_id Job's pre-snapshot task id.
     * @param string $owner   Restore job id as a string.
     *
     * @return bool Whether the matching handle was cleared.
     */
    private function release_pre_snapshot_handle( object $website, string $task_id, string $owner ): bool {
        $db     = MainWP_DB::instance();
        $raw    = $db->get_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
        $handle = json_decode( (string) $raw, true );
        if ( ! is_array( $handle ) || ! isset( $handle['task_id'] ) || ! is_scalar( $handle['task_id'] ) ) {
            return false;
        }

        $handle_task_id = (string) $handle['task_id'];
        $handle_owner   = $handle['owner'] ?? '';
        if ( '' === $handle_task_id || $handle_task_id !== $task_id || ! is_scalar( $handle_owner ) || (string) $handle_owner !== $owner ) {
            return false;
        }

        return $db->compare_and_swap_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, (string) $raw, '' );
    }

    /**
     * Ask the child to cancel its in-flight restore task, once.
     *
     * The child refuses while another runner holds the task lease (a
     * slice in flight) - that answer is surfaced as busy so the caller
     * can defer instead of waiting. A child task that already completed
     * cannot be cancelled - the restore happened - while one that
     * already failed needs no child-side work.
     *
     * @param object $job Job row.
     *
     * @throws MainWP_Exception When the child returned an error.
     *
     * @return string 'ok' (cancelled), 'failed' (already failed, nothing
     *                to cancel), 'completed' (restore happened),
     *                'busy' (slice in flight), or 'rolling_back' (rollback in progress).
     */
    private function request_child_cancel( object $job ): string {
        $website = $this->website( $job );

        $params  = ! empty( $job->child_task_id )
            ? array( 'task_id' => $job->child_task_id )
            : array( 'restore_id' => $job->checkpoint['restore_id'] );
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_cancel', $params, false, false, false );
        $result  = is_array( $payload ) && isset( $payload['result'] ) ? (string) $payload['result'] : '';
        $phase   = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';

        if ( 'ok' === $result ) {
            return 'ok';
        }
        if ( 'not_found' === $result ) {
            return 'failed';
        }
        if ( 'already_terminal' === $result ) {
            return 'completed' === $phase ? 'completed' : 'failed';
        }
        if ( in_array( $result, array( 'busy', 'rolling_back' ), true ) ) {
            return $result;
        }

        $message = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not confirm the cancellation.', 'mainwp' );
        throw new MainWP_Exception( esc_html( $message ) );
    }

    /**
     * Ask the Child to cancel, reading a thrown or unreadable reply as unconfirmed.
     *
     * @param object $job Job row; a recorded child_task_id sends the cancel by id.
     *
     * @return string request_child_cancel()'s answer, or 'unconfirmed'.
     */
    private function request_child_cancel_or_unconfirmed( object $job ): string {
        try {
            return $this->request_child_cancel( $job );
        } catch ( \Throwable $e ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its child task: %s', (int) $job->id, $e->getMessage() ), (int) $job->site_id, 0 );
            return 'unconfirmed';
        }
    }

    /**
     * Cancel a Child task whose create answer arrived after this job ended.
     *
     * A UI cancel can reach the Child while the create request is still in
     * flight, find no task by restore_id and finish as confirmed. The create
     * then leaves a task no job owns, and a queued cloud pull runs on its own.
     * A job that is still active belongs to a worker whose create reuses the
     * task by restore_id, and an unreadable row proves nothing, so only a
     * terminal row sends the cancel.
     *
     * @param object $job     Job row.
     * @param string $task_id Child task id the create returned.
     *
     * @return bool Whether the job had ended, so the task id must not be used.
     */
    private function cancel_task_created_after_terminal( object $job, string $task_id ): bool {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $fresh = $store->get_job( (int) $job->id );
        if ( null === $fresh || in_array( $fresh->status, $store::active_statuses(), true ) ) {
            return false;
        }
        $result = '';
        $phase  = '';
        try {
            $website = $this->website( $job );
            $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_cancel', array( 'task_id' => $task_id ), false, false, false );
            $result  = is_array( $payload ) && isset( $payload['result'] ) ? (string) $payload['result'] : '';
            $phase   = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
        } catch ( \Throwable $e ) {
            $result = '';
        }
        if ( 'ok' === $result ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: cancelled child restore task %s, created after the job ended.', (int) $job->id, $task_id ), (int) $job->site_id, 0 );
        } elseif ( 'already_terminal' === $result && 'completed' === $phase ) {
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: child restore task %s, created after the job ended, finished restoring the site. Check the site.', (int) $job->id, $task_id ), (int) $job->site_id, 0 );
        } elseif ( 'already_terminal' === $result ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: child restore task %s, created after the job ended, had already stopped.', (int) $job->id, $task_id ), (int) $job->site_id, 0 );
        } else {
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: child restore task %s was created after the job ended and could not be cancelled; it may need manual cleanup on the site.', (int) $job->id, $task_id ), (int) $job->site_id, 0 );
        }
        return true;
    }

    /**
     * Apply a recorded cancel intent before driving the child.
     *
     * @param object $job Job row.
     *
     * @return string One of the TERMINAL_OUTCOME_* constants.
     */
    private function apply_cancel_intent( object $job ): string {
        $failure = $job->checkpoint['terminal_failure'] ?? array();
        return $this->record_terminal_failure( $job, $failure['error'] ?? null, $failure['error_code'] ?? '', $this->lock_token );
    }

    /**
     * Schedule an immediate continuation tick on the dedicated restore
     * lane so a job advances without waiting for the 5-minute backup
     * continuation cron.
     *
     * WordPress de-duplicates an identical pending single event within its
     * ~10-minute window, and spawn_cron only spawns once per
     * WP_CRON_LOCK_TIMEOUT, so repeated calls collapse into a natural
     * throttle rather than a busy loop. Under DISABLE_WP_CRON spawn_cron
     * is a no-op and the periodic cron remains the backstop.
     */
    protected function schedule_continuation(): void {
        if ( ! wp_next_scheduled( 'mainwp_restore_continue_action' ) ) {
            wp_schedule_single_event( time(), 'mainwp_restore_continue_action' );
        }
        spawn_cron();
    }

    /**
     * Drive pending restore jobs for one budgeted cron slice.
     *
     * Cheap phases (pending -> preflight -> staging ...) are drained in a
     * single slice instead of one phase per 5-minute tick: the loop keeps
     * advancing the job while it makes forward progress to another active
     * phase and the budget holds. A phase that returns without changing the
     * status (the child is still working), reaches a terminal state, or
     * backs off (failed_retryable) ends the drain. When the slice ends with
     * the job still mid-flight, a near-term continuation is scheduled so the
     * next poll does not wait for the periodic cron.
     *
     * @throws \Throwable When the reclaim sweep or lease acquisition fails.
     *
     * @param int      $budget_seconds Slice budget.
     * @param int|null $only_job_id    Drive only this job (a foreground poll
     *                                 advancing the restore it polls); null
     *                                 drives the next runnable job of any site,
     *                                 the cron behavior.
     */
    public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $only_job_id = null ): void {
        // Every checkpoint, status and cancel write names the revision column,
        // so driving the site without it would advance a restore nothing can
        // record. The job keeps its row and resumes after the repair.
        if ( ! MainWP_Install::backup_restore_job_checkpoint_rev_ready() ) {
            return;
        }
        $deadline = microtime( true ) + $budget_seconds;
        $token    = 'restore-' . wp_generate_password( 12, false );
        $store    = MainWP_Backup_Restore_Job_Store::instance();

        $lease_seconds = $budget_seconds + 60;

        /**
         * Filters the bound applied to child round trips during a restore
         * slice, so a single hung request cannot outlive the slice's lease
         * (the connect layer's own backstop is 20 hours).
         *
         * @param int $bound Timeout bound in seconds; defaults to the lease.
         */
        $request_bound = (int) apply_filters( 'mainwp_restore_child_request_timeout', $lease_seconds );
        $cap_timeout   = function ( $timeout ) use ( $request_bound ) {
            return min( (int) $timeout, max( 30, $request_bound ) );
        };
        add_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );

        // The sweep terminalizes jobs, which fires a third-party action and
        // touches the filesystem, so it can throw. Leaving the cap registered
        // would then bound every later child request in this process,
        // including backup calls that have nothing to do with a restore.
        try {
            $now = time();
            foreach ( $store->find_jobs_over_reclaim_ceiling( $store::active_statuses(), $now, $only_job_id ) as $expired_job ) {
                $outcome = $this->record_terminal_failure(
                    $expired_job,
                    sprintf(
                        /* translators: %d: number of times the restore was interrupted. */
                        esc_html__( 'The restore was interrupted %d times without finishing a step, so it was stopped. Run the restore again. If it stops again, check the PHP error log on the Dashboard server.', 'mainwp' ),
                        $store::RECLAIM_CEILING
                    ),
                    '',
                    (string) $expired_job->locked_by
                );
                if ( self::TERMINAL_OUTCOME_DRIVE_ON === $outcome ) {
                    // The Child finished the restore, so the job drives on. Acquire
                    // skips a row that keeps a holder at the reclaim ceiling, so the
                    // dead holder's lease is released; the ceiling still ends the job
                    // if its next worker dies too.
                    $driven = $store->get_job( (int) $expired_job->id );
                    if ( null !== $driven && in_array( $driven->status, $store::active_statuses(), true ) ) {
                        $store->set_status(
                            (int) $expired_job->id,
                            $driven->status,
                            array(
                                'locked_by'    => '',
                                'locked_until' => 0,
                            ),
                            (string) $expired_job->locked_by
                        );
                    }
                }
            }

            $job = $store->acquire_next_job( $token, $lease_seconds, $now, $only_job_id );
        } catch ( \Throwable $e ) {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            throw $e;
        }

        if ( null === $job ) {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            return;
        }
        $job_id = (int) $job->id;

        $this->lock_token    = $token;
        $this->lease_seconds = $lease_seconds;

        try {
            while ( true ) {
                // A slice that lost its lease (reclaimed after an expiry)
                // stops driving: the new holder owns the job now, and every
                // fenced write below would be refused anyway.
                if ( ! $store->renew_lease( $job_id, $token, $this->lease_seconds ) ) {
                    break;
                }

                $before = $job->status;
                $this->run_job( $job, $deadline );

                $job = $store->get_job( $job_id );
                if ( null === $job ) {
                    break;
                }
                $after = $job->status;
                if ( $after === $before
                    || MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $after
                    || ! in_array( $after, MainWP_Backup_Restore_Job_Store::active_statuses(), true )
                    || microtime( true ) >= $deadline
                ) {
                    break;
                }
            }
        } catch ( \Throwable $e ) {
            $code = ( $e instanceof MainWP_Exception ) ? $e->get_message_error_code() : '';
            // An unclassified result is no evidence, so history keeps the replay tag a checkpoint may hold.
            $history_code = MainWP_Backup_Error_Codes::code_for_exception( $e );
            $history_code = MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR === $history_code ? null : $history_code;
            // The site layout does not change between retries.
            $this->record_failure( $job, MainWP_Backup_Error_Codes::sanitize_message( $e->getMessage() ), MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT !== $code, $code, $history_code );
        } finally {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            $store->release_lock( $job_id, $token );
            $this->lock_token    = null;
            $this->lease_seconds = 0;
        }

        // Still mid-flight on a phase that polls the child: kick a near-term
        // continuation rather than idling until the 5-minute cron. Skipped
        // for terminal jobs and for failed_retryable (its next_retry_at
        // backoff gates re-acquisition, so a spawn now would only no-op).
        $job = $store->get_job( $job_id );
        if ( null !== $job
            && MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE !== $job->status
            && in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true )
        ) {
            $this->schedule_continuation();
        }
    }

    /**
     * Renew the current slice's lease between long operations.
     *
     * Called inside the child poll loops so a slow-but-alive worker keeps
     * its lease instead of drifting toward a reclaim. False means the
     * lease was already reclaimed - the caller stops its phase; the new
     * holder owns the job. Callers outside a leased slice (direct
     * run_job() consumers) hold no token and always continue.
     *
     * @param object $job Job row.
     *
     * @return bool True when work on the job may continue.
     */
    private function lease_still_held( object $job ): bool {
        if ( null === $this->lock_token ) {
            return true;
        }
        return MainWP_Backup_Restore_Job_Store::instance()->renew_lease( (int) $job->id, $this->lock_token, $this->lease_seconds );
    }

    /**
     * Advance one job within the deadline.
     *
     * @param object $job      Locked job row.
     * @param float  $deadline Slice deadline.
     */
    public function run_job( object $job, float $deadline ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- phase dispatch.
        $status = $job->status;
        if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
            // Resume the phase that failed.
            $status = isset( $job->checkpoint['resume_status'] ) ? (string) $job->checkpoint['resume_status'] : MainWP_Backup_Restore_Job_Store::STATUS_PENDING;
        }

        // An applicable cancel must stop the worker before it advances the restore.
        if ( self::has_applicable_cancel_intent( $job ) ) {
            if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                return;
            }
            // The child completed its restore: the intent was cleared on
            // the row, so the stale copy must not be written back below.
            $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
            if ( null === $job ) {
                return;
            }
            // A verification retry can drive on without changing the stored retry status.
            $status = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status
                ? ( $job->checkpoint['resume_status'] ?? MainWP_Backup_Restore_Job_Store::STATUS_PENDING )
                : $job->status;
        }

        switch ( $status ) {
            case MainWP_Backup_Restore_Job_Store::STATUS_PENDING:
                $this->phase_pending( $job );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT:
                $this->phase_preflight( $job );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT:
                $this->phase_pre_snapshot( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_STAGING:
                $this->phase_staging( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING:
                $this->phase_transferring( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_RUNNING:
                $this->phase_running( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING:
                $this->phase_verifying( $job );
                break;
            default:
                break; // Terminal states have nothing to drive.
        }
    }

    /**
     * Pending: route to the pre-restore safety backup or straight to
     * staging.
     *
     * @param object $job Job row.
     */
    private function phase_pending( object $job ): void {
        // Preflight runs first so a doomed restore (a vanished cloud artifact)
        // is stopped before the optional safety backup spends real work.
        MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT, array(), $this->lock_token );
    }

    /**
     * Preflight: fail a doomed restore fast, before any transfer or
     * destructive work, then route to the optional safety backup or
     * straight to staging.
     *
     * The cheap, high-value dashboard check is remote-artifact
     * availability: a snapshot the child would pull from cloud storage must
     * still exist there. Disk, database-permission, and writeability checks
     * run child-side as the restore task starts.
     *
     * @param object $job Job row.
     *
     * @throws MainWP_Exception When the safety backup cannot start for a reason other than the site layout.
     */
    private function phase_preflight( object $job ): void {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $plan  = isset( $job->checkpoint['plan'] ) ? (array) $job->checkpoint['plan'] : array();

        if ( ! $this->transfer_driver()->remote_artifacts_present( $plan ) ) {
            $this->record_failure(
                $job,
                MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_RESTORE_ARTIFACT_MISSING ),
                false,
                MainWP_Backup_Error_Codes::ERR_RESTORE_ARTIFACT_MISSING
            );
            return;
        }

        if ( ! empty( $job->checkpoint['options']['pre_snapshot'] ) ) {
            try {
                $task_id = MainWP_Backup_Executor::trigger_backup( (int) $job->site_id, 'full', (string) $job->id );
            } catch ( MainWP_Exception $e ) {
                if ( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT !== $e->get_message_error_code() ) {
                    throw $e;
                }
                // The safety backup is always a full backup, which the child refuses
                // on this layout. A database-only restore still works without it.
                // No apostrophes: the restore modal prints last_error as stored.
                $message = 'db' === ( $job->checkpoint['sub_type'] ?? '' )
                    ? esc_html__( 'The safety backup cannot run on this site because this site stores its wp-content folder outside the WordPress core folder. The restore was not started and nothing on the site was changed. To restore only the database, start the restore again with the safety backup turned off.', 'mainwp' )
                    : MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT );
                $this->record_failure( $job, $message, false, MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_LAYOUT );
                return;
            }
            if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $task_id ), $this->lock_token ) ) {
                // A cancel or failure can commit while the Child create is in flight,
                // before the safety row exists for its terminal path to close.
                $fresh = $store->get_job( (int) $job->id );
                if ( null !== $fresh && ! in_array( $fresh->status, $store::active_statuses(), true ) ) {
                    $cancelled = $store::STATUS_CANCELLED === $fresh->status;
                    MainWP_Backup_Run_History::finalize_restore_safety( (int) $job->id, $cancelled ? 'cancelled' : 'failed', $cancelled ? '' : (string) ( $fresh->checkpoint['last_error_code'] ?? '' ) );
                }
            }
            return;
        }

        $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
    }

    /**
     * Pre-snapshot: drive the safety backup task; its artifacts stay on
     * the child as the rollback source.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_pre_snapshot( object $job, float $deadline ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        if ( ! empty( $job->checkpoint['pre_snapshot_reconcile_pending'] ) && (int) $job->next_retry_at > time() ) {
            return;
        }

        while ( microtime( true ) < $deadline ) {
            $reconcile = ! empty( $job->checkpoint['pre_snapshot_reconcile_pending'] );
            // A marker without the flag cannot tell an arm before the run from a replay; read it as armed.
            $confirmed_replay = $reconcile && ! empty( $job->checkpoint['pre_snapshot_replay_confirmed'] );
            $operation        = $reconcile ? 'backup_task_status' : 'backup_task_run';
            if ( ! $reconcile ) {
                // Arm the read before leaving: a reclaimed worker cannot checkpoint a replay afterward.
                $checkpoint                                   = $job->checkpoint;
                $checkpoint['pre_snapshot_reconcile_pending'] = true;
                $checkpoint['pre_snapshot_replay_confirmed']  = false;
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    return;
                }
                $job->checkpoint = $checkpoint;
            }
            $diag_start = microtime( true );
            $payload    = MainWP_Connect::fetch_url_authed( $website, $operation, array( 'task_id' => $job->pre_snapshot_task_id ), false, false, false );

            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action(
                'mainwp_backup_diag_poll',
                is_array( $payload ) ? $payload : array(),
                microtime( true ) - $diag_start,
                array(
                    'op'      => 'pre_snapshot',
                    'site_id' => (int) $job->site_id,
                    'task_id' => 0,
                )
            );

            // The child round trip may have outlasted the lease; a worker
            // that lost it stops here instead of writing over the new holder.
            if ( ! $this->lease_still_held( $job ) ) {
                return;
            }

            if ( 'backup_task_run' === $operation && is_array( $payload ) && 'AUTH_ERROR2' === ( $payload['error_code'] ?? '' ) ) {
                $checkpoint                                   = $job->checkpoint;
                $checkpoint['pre_snapshot_reconcile_pending'] = true;
                $checkpoint['pre_snapshot_replay_confirmed']  = true;
                $job->attempt_count                           = 0;
                $this->defer_pre_snapshot_reconciliation( $job, $checkpoint );
                return;
            }

            $phase = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';

            if ( '' !== $phase && ! isset( $payload['error'] ) ) {
                $previous = (array) ( $job->checkpoint['pre_snapshot_progress'] ?? array() );
                $progress = isset( $payload['progress'] ) && is_array( $payload['progress'] ) ? $payload['progress'] : array();
                $observed = array(
                    'phase'            => sanitize_key( $phase ),
                    'files'            => max( 0, (int) ( $progress['files_scanned'] ?? $progress['files_processed'] ?? $previous['files'] ?? 0 ) ),
                    'bytes'            => max( 0, (int) ( $progress['bytes_done'] ?? $previous['bytes'] ?? 0 ) ),
                    'last_progress_at' => isset( $payload['last_progress_at'] ) && is_string( $payload['last_progress_at'] ) && '' !== $payload['last_progress_at'] ? $payload['last_progress_at'] : (string) ( $previous['last_progress_at'] ?? '' ),
                );
                $saved    = ( $previous['phase'] ?? '' ) !== $observed['phase']
                    || $observed['files'] > (int) ( $previous['files'] ?? 0 )
                    || $observed['bytes'] > (int) ( $previous['bytes'] ?? 0 );
                $touched  = '' !== $observed['last_progress_at'] && (string) ( $previous['last_progress_at'] ?? '' ) !== $observed['last_progress_at'];
                // A heartbeat moves last_progress_at before anything is saved, so only a
                // confirmed replay lets it renew attempts: the Child received the run and
                // recorded its request id, normally because it executed the run.
                $moved = $saved || ( $touched && $confirmed_replay );
                if ( $reconcile && ! $saved && ! $touched && ! in_array( $phase, array( 'completed', 'failed' ), true ) ) {
                    if ( $confirmed_replay ) {
                        $this->defer_pre_snapshot_reconciliation( $job, $job->checkpoint );
                        return;
                    }
                    // A transport failure may have happened before the Child received the run.
                    $checkpoint = $job->checkpoint;
                    unset( $checkpoint['pre_snapshot_reconcile_pending'], $checkpoint['pre_snapshot_replay_confirmed'] );
                    if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                        return;
                    }
                    $job->checkpoint = $checkpoint;
                    continue;
                }
                $checkpoint                          = $job->checkpoint;
                $checkpoint['pre_snapshot_progress'] = $observed;
                unset( $checkpoint['pre_snapshot_reconcile_pending'], $checkpoint['pre_snapshot_replay_confirmed'] );
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    return;
                }
                $job->checkpoint = $checkpoint;
                if ( $moved || in_array( $phase, array( 'completed', 'failed' ), true ) ) {
                    if ( ! $store->set_status(
                        (int) $job->id,
                        MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
                        array(
                            'attempt_count' => 0,
                            'next_retry_at' => 0,
                        ),
                        $this->lock_token
                    ) ) {
                        return;
                    }
                    $job->attempt_count = 0;
                }
            }

            if ( 'completed' === $phase ) {
                $artifacts = $this->safety_backup_artifacts( $payload, (string) $job->pre_snapshot_task_id );
                if ( empty( $artifacts ) ) {
                    MainWP_Backup_Run_History::finalize_restore_safety( (int) $job->id, 'failed' );
                    // Same verdict as a failed safety backup. The handle stays
                    // with this job so the terminal path stops or prunes the
                    // unusable Child task instead of leaving it behind.
                    $this->record_failure( $job, esc_html__( 'The pre-restore safety backup failed.', 'mainwp' ), false, '' );
                    return;
                }
                $checkpoint                           = $job->checkpoint;
                $checkpoint['pre_snapshot_artifacts'] = $artifacts;
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    // Without a durable record nothing could roll back to this
                    // backup, so keep the handle and the phase and let the
                    // retry budget poll the finished task again.
                    // No apostrophe: the restore modal prints last_error as stored.
                    $this->record_failure( $job, esc_html__( 'The safety backup finished, but MainWP could not record it. MainWP will try again.', 'mainwp' ), true, '' );
                    return;
                }

                // A persisted handle would short-circuit the NEXT restore's
                // trigger_backup() into reusing this finished task, and its
                // later success would prune an artifact this job still counts
                // on for rollback.
                $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );

                MainWP_Backup_Run_History::finalize_restore_safety( (int) $job->id, 'completed' );

                // The site keeps one safety backup. Older ones go only once
                // this one is durably recorded, and while this job still holds
                // the site, so no rollback of an older restore can start.
                $this->prune_superseded_pre_snapshots( $job );

                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
                return;
            }
            if ( 'failed' === $phase || '' === $phase || ( $reconcile && isset( $payload['error'] ) ) ) {
                $error      = is_array( $payload ) && isset( $payload['error'] ) ? MainWP_Backup_Error_Codes::sanitize_message( (string) $payload['error'] ) : esc_html__( 'The pre-restore safety backup failed.', 'mainwp' );
                $error_code = MainWP_Backup_Error_Codes::code_for_exception( new MainWP_Exception( $error, null, is_array( $payload ) && is_string( $payload['error_code'] ?? null ) ? $payload['error_code'] : '' ) );
                if ( 'failed' === $phase ) {
                    MainWP_Backup_Run_History::finalize_restore_safety( (int) $job->id, 'failed', $error_code );
                    $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );
                    $this->record_failure( $job, $error, false, $error_code );
                } else {
                    // The child task is still running: a shapeless envelope is a
                    // transport hiccup. Keep polling within the attempt budget.
                    // No apostrophe in this string: it is escaped on the way in
                    // and the restore modal prints last_error as stored.
                    $this->record_failure( $job, esc_html__( 'The site did not send a readable answer about the safety backup. The backup may still be running, so MainWP will try again.', 'mainwp' ), true, '' );
                }
                return;
            }
            if ( $reconcile ) {
                return;
            }
        }
    }

    /**
     * Spend a safety-backup attempt and defer the next status read for one reconcile interval.
     *
     * @param object $job Restore job.
     * @param array  $checkpoint Safety-backup reconciliation state.
     */
    private function defer_pre_snapshot_reconciliation( object $job, array $checkpoint ): void {
        $store    = MainWP_Backup_Restore_Job_Store::instance();
        $attempts = (int) $job->attempt_count + 1;
        if ( MainWP_Backup_Backoff::attempts_exhausted( $attempts ) && ! empty( $checkpoint['pre_snapshot_replay_confirmed'] ) ) {
            $checkpoint['last_error_extra'] = 'AUTH_ERROR2';
        }
        if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
            return;
        }
        if ( MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            $this->record_failure( $job, MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_TIMEOUT ), false, MainWP_Backup_Error_Codes::ERR_TIMEOUT );
            return;
        }
        $store->set_status(
            (int) $job->id,
            MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
            array(
                'attempt_count' => $attempts,
                'next_retry_at' => time() + self::RECONCILE_INTERVAL_SECONDS,
            ),
            $this->lock_token
        );
    }

    /**
     * Staging: make every plan artifact a verified local file, then
     * create the child restore task.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_staging( object $job, float $deadline ): void {
        $store      = MainWP_Backup_Restore_Job_Store::instance();
        $checkpoint = $job->checkpoint;
        $plan       = (array) $checkpoint['plan'];
        $driver     = $this->transfer_driver();

        // The Child reported this restore completed while its task id was never
        // recorded. The create returns that task for the same restore_id, so ask
        // before staging, which is not needed and may no longer be possible.
        if ( empty( $job->child_task_id ) && ! empty( $checkpoint['child_completed_unrecorded'] ) ) {
            unset( $checkpoint['child_completed_unrecorded'] );
            $created = $this->create_child_restore_task( $job, $checkpoint, $plan );
            if ( '' === $created['task_id'] || ! empty( $created['reused'] ) ) {
                return;
            }
            // A new task means the finished one is gone. Record it before staging,
            // the way a rejected pull manifest does, so the next slice stages and
            // streams into it and a cancel can always reach it by id.
            if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
                $this->cancel_task_created_after_terminal( $job, $created['task_id'] );
            }
            return;
        }

        // Cloud pull: when the destination can presign and the child can
        // download, the dashboard never touches the bytes - the child
        // task is created with a presigned manifest and the job goes
        // straight to running. Any gap (gate fails, manifest incomplete,
        // child rejects it, pull fails later) lands on the staged
        // transfer below instead.
        if ( empty( $job->child_task_id ) && $driver->can_use_cloud_pull( $this->website( $job ), $plan, $checkpoint ) ) {
            $pull_manifest = $driver->generate_pull_manifest( $plan );
            if ( null !== $pull_manifest ) {
                $checkpoint['plan'] = $plan; // Expected hashes filled from the upload records.
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    return; // Nothing exists on the site yet; the next slice builds the manifest again.
                }

                $created = $this->create_child_restore_task( $job, $checkpoint, $plan, $pull_manifest );
                if ( '' === $created['task_id'] || ! empty( $created['reused'] ) ) {
                    return; // Reuse already reconciled the job; a failed create must also stop here.
                }

                if ( $created['pull_rejected'] ) {
                    // The child created the task but refused the
                    // manifest: stage and stream into that same task.
                    $checkpoint['cloud_pull_tried'] = true;
                    // A refused save is harmless: once child_task_id lands, the
                    // cloud pull gate above is skipped without this flag.
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
                        // Lease lost after the child task was created: its id
                        // never landed, so surface the orphan and stop.
                        do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
                        $this->cancel_task_created_after_terminal( $job, $created['task_id'] );
                        return;
                    }
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the site declined the direct cloud download; using the standard transfer.', (int) $job->id ), (int) $job->site_id, 0 );
                    return;
                }

                if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
                    // The lease was lost in the window between creating the
                    // child task and recording its id. Surface the orphaned
                    // child task and stop; the reclaiming worker owns the job.
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
                    $this->cancel_task_created_after_terminal( $job, $created['task_id'] );
                    return;
                }
                $artifact_count = count( $pull_manifest['artifacts'] );
                do_action(
                    'mainwp_log_info',
                    1 === $artifact_count
                        ? sprintf( 'Restore job %d: the site downloads 1 backup file directly from cloud storage.', (int) $job->id )
                        : sprintf( 'Restore job %d: the site downloads %d backup files directly from cloud storage.', (int) $job->id, $artifact_count ),
                    (int) $job->site_id,
                    0
                );
                return;
            }
        }

        $needs_staging = false;
        foreach ( $plan as $entry ) {
            if ( empty( $entry['adopt_only'] ) ) {
                $needs_staging = true;
                break;
            }
        }

        if ( $needs_staging ) {
            $outcome            = MainWP_Backup_Restore_Transfer_Driver::instance()->stage_artifacts( (int) $job->id, $plan, $deadline );
            $checkpoint['plan'] = $plan;
            $saved              = $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

            if ( null !== $outcome['error'] ) {
                $this->record_failure( $job, (string) $outcome['error'], ! empty( $outcome['retryable'] ), isset( $outcome['error_code'] ) ? (string) $outcome['error_code'] : '' );
                return;
            }
            if ( ! $saved || ! $outcome['done'] ) {
                return; // More staging next tick, or the staged plan has to be saved first.
            }
        }

        // A child task may already exist when a rejected pull manifest
        // routed this job back through staging.
        if ( ! empty( $job->child_task_id ) ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array(), $this->lock_token );
            return;
        }

        $created = $this->create_child_restore_task( $job, $checkpoint, $plan );
        if ( '' === $created['task_id'] || ! empty( $created['reused'] ) ) {
            return; // Reuse already reconciled the job; a failed create must also stop here.
        }

        if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
            // Lease lost between creating the child task and recording its id.
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
            $this->cancel_task_created_after_terminal( $job, $created['task_id'] );
            return;
        }
    }

    /**
     * The restore transfer driver (test seam).
     *
     * @return MainWP_Backup_Restore_Transfer_Driver
     */
    protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
        return MainWP_Backup_Restore_Transfer_Driver::instance();
    }

    /**
     * Transferring: stream staged artifacts to the child.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_transferring( object $job, float $deadline ): void {
        $store         = MainWP_Backup_Restore_Job_Store::instance();
        $fresh         = $store->get_job( (int) $job->id );
        $still_driving = array(
            MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
            MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
        );
        if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
            return;
        }
        // A UI cancel may have arrived after run_job read its entry checkpoint.
        $job = $fresh;
        if ( ! empty( $job->checkpoint['cancel_intent'] ) ) {
            if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                return;
            }
            $job = $store->get_job( (int) $job->id );
            // If the child finished during cancel, record_terminal_failure() moved the row to running; artifact transfer must never resume.
            if ( null === $job || ! in_array( $job->status, $still_driving, true ) ) {
                return;
            }
        }

        $checkpoint = $job->checkpoint;
        $cursor     = isset( $checkpoint['transfer'] ) ? (array) $checkpoint['transfer'] : array();

        $outcome = MainWP_Backup_Restore_Transfer_Driver::instance()->transfer_artifacts(
            $this->website( $job ),
            (string) $job->child_task_id,
            (array) $checkpoint['plan'],
            $cursor,
            $deadline,
            function () use ( $store, $job, $still_driving ): bool {
                $fresh = $store->get_job( (int) $job->id );
                return null !== $fresh && empty( $fresh->checkpoint['cancel_intent'] ) && in_array( $fresh->status, $still_driving, true );
            },
            (int) $job->id
        );

        // Another lane may have taken over during the chunk round trip.
        $fresh = $store->get_job( (int) $job->id );
        if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
            return;
        }

        $checkpoint             = $fresh->checkpoint;
        $checkpoint['transfer'] = $cursor;
        if ( ! empty( $outcome['restage'] ) ) {
            $checkpoint['plan'] = $outcome['plan'];
        }
        $saved = $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

        if ( ! empty( $fresh->checkpoint['cancel_intent'] ) ) {
            // Cancellation owns the outcome, including an already-completed child.
            $this->apply_cancel_intent( $fresh );
            return;
        }

        if ( null !== $outcome['error'] ) {
            $this->record_failure( $job, $outcome['error'], $outcome['retryable'], (string) ( $outcome['error_code'] ?? '' ) );
            return;
        }
        if ( ! $saved ) {
            // Resending from the stored cursor is safe: the site answers a gap
            // with the offset it expects.
            return;
        }
        if ( ! empty( $outcome['restage'] ) ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
            return;
        }
        if ( $outcome['done'] ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array(), $this->lock_token );
        }
    }

    /**
     * Running: drive the child restore task within the slice budget.
     *
     * The child advances its restore one step per restore_task_run round
     * trip, so the poll loops until the child reaches a terminal phase or
     * the slice deadline passes - the same shape as the pre_snapshot,
     * staging, and transferring phases. Polling within the slice (rather
     * than one round trip per cron tick) is what lets a restore finish in
     * a single continuation: the post-slice continuation kick is a no-op
     * when fired from inside a cron run (WP-Cron holds its own lock), so a
     * once-per-tick poll would otherwise fall back to the 5-minute backstop
     * between child steps on a low-traffic dashboard.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_running( object $job, float $deadline ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        while ( microtime( true ) < $deadline ) {
            // A cancel recorded while the previous round trip was in
            // flight must stop the restore before the child is driven
            // again: re-read the row and apply the intent exactly as
            // run_job's entry check does. Working from the fresh row also
            // rebases this loop's checkpoint writes, so a slice that
            // outlasted a cancel can no longer resurrect its stale copy.
            $fresh         = $store->get_job( (int) $job->id );
            $still_driving = array(
                MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
                // A retry-resume drives the child while the row still says
                // failed_retryable; run_job mapped it here via resume_status.
                MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
            );
            if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
                return; // Cancelled, completed, or advanced by another lane.
            }
            $job = $fresh;
            if ( ! empty( $job->checkpoint['cancel_intent'] ) ) {
                if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                    return; // Cancelled, or the child is still busy.
                }
                // The child already completed its restore: the intent was
                // cleared, and the next round trip reports completed.
                $job = $store->get_job( (int) $job->id );
                if ( null === $job ) {
                    return;
                }
            }

            $diag_start = microtime( true );
            $payload    = MainWP_Connect::fetch_url_authed( $website, 'restore_task_run', array( 'task_id' => $job->child_task_id ), false, false, false );

            /**
             * Fires after each restore poll round trip with the full child
             * response, so the diagnostics recorder can capture the
             * non-terminal observations this loop otherwise discards.
             *
             * @param int   $job_id   Restore job id.
             * @param int   $site_id  Child site id.
             * @param array $payload  Full child response payload.
             * @param float $duration HTTP round-trip duration in seconds.
             */
            do_action( 'mainwp_backup_diag_restore_poll', (int) $job->id, (int) $job->site_id, is_array( $payload ) ? $payload : array(), microtime( true ) - $diag_start );

            // The child round trip may have outlasted the lease; a worker
            // that lost it stops here instead of writing over the new holder.
            if ( ! $this->lease_still_held( $job ) ) {
                return;
            }

            // A terminal failed phase carries an error key too - phase wins.
            if ( ! is_array( $payload ) || ( ! isset( $payload['phase'] ) && isset( $payload['error'] ) ) ) {
                $message    = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site returned no restore status.', 'mainwp' );
                $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
                $this->record_failure( $job, $message, true, $error_code );
                return;
            }

            $phase = isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
            if ( 'completed' === $phase ) {
                $checkpoint                 = $job->checkpoint;
                $verification               = isset( $payload['sub_state']['verification'] ) && is_array( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
                $checkpoint['verification'] = $this->apply_withheld_reconcile_reason( $verification, $checkpoint );
                // Carry the order-preservation outcome (customers and stock
                // re-applied) alongside verification so the dashboard reports
                // what was kept rather than hiding it.
                if ( isset( $payload['sub_state']['progress']['woo_preserve'] ) ) {
                    $checkpoint['verification']['woo_preserve'] = $payload['sub_state']['progress']['woo_preserve'];
                }
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    return; // The site reports the finished task again on the next poll.
                }
                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array(), $this->lock_token );
                return;
            }
            if ( 'failed' === $phase ) {
                $this->handle_failed_child_task( $job, $payload );
                return;
            }
            // Still working within this slice: poll the child again.
        }
        // Slice budget spent with the child still working: the next
        // continuation (or the periodic cron) polls again.
    }

    /**
     * Verifying: external reachability check, completion hook, cleanup.
     *
     * @param object $job Job row.
     */
    private function phase_verifying( object $job ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        $reachable = $this->site_responds( $website );

        $checkpoint                                  = $job->checkpoint;
        $checkpoint['verification']                  = isset( $checkpoint['verification'] ) && is_array( $checkpoint['verification'] ) ? $checkpoint['verification'] : array();
        $checkpoint['verification']['site_responds'] = $reachable;
        $saved                                       = $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

        if ( ! $reachable ) {
            $this->record_failure( $job, esc_html__( 'The site did not respond after the restore. The restore completed on the child; check the site directly.', 'mainwp' ), true );
            return;
        }
        if ( ! $saved ) {
            return;
        }

        // Prune while this job still holds the site's active reservation: a
        // rollback of an earlier restore is refused until it ends, so none can
        // start from a safety backup the child is about to delete.
        $this->prune_superseded_pre_snapshots( $job );
        if ( ! empty( $checkpoint['is_rollback'] ) ) {
            $this->retire_rollback_source( $job, $checkpoint );
        }

        if ( ! $store->complete_job( (int) $job->id, $this->lock_token ) ) {
            // Already terminal, or this worker's lease was reclaimed - the
            // completion (and its event below) belongs to the live holder.
            return;
        }
        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );

        /**
         * Fires after a restore completes and the site responds.
         *
         * The logs connector records the event.
         *
         * @param array $event Site, snapshot, job, and verification details.
         */
        do_action(
            'mainwp_backup_restored',
            array(
                'site_id'      => (int) $job->site_id,
                'snapshot_id'  => (int) $job->snapshot_id,
                'job_id'       => (int) $job->id,
                'verification' => $checkpoint['verification'],
            )
        );
    }

    /**
     * Create the child restore task from the staged plan.
     *
     * @param object     $job           Job row.
     * @param array      $checkpoint    Checkpoint.
     * @param array      $plan          Staged plan.
     * @param array|null $pull_manifest Presigned pull manifest, when the
     *                                  child downloads from cloud storage.
     *
     * @return array { task_id: string ('' on failure, lease loss or refused writes),
     *                 pull_rejected: bool, reused?: bool }.
     */
    private function create_child_restore_task( object $job, array &$checkpoint, array $plan, ?array $pull_manifest = null ): array {
        // Re-check the lease immediately before this irreversible side effect.
        // Staging can run long enough for the lease to expire and another lane
        // to reclaim the job; without this a lease-lost worker would create a
        // real child restore task the Dashboard job no longer owns (and would
        // not record, since the fenced writes afterward are refused), leaving
        // an orphaned runnable restore on the child. A lost lease returns no
        // task id so the caller stops and the reclaiming worker drives instead.
        if ( ! $this->lease_still_held( $job ) ) {
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }

        $website   = $this->website( $job );
        $options   = array(
            'sub_type'         => (string) $checkpoint['sub_type'],
            'has_foreign_keys' => null,
            'file_rollback'    => ! empty( $checkpoint['options']['file_rollback'] ) || ( empty( $checkpoint['is_rollback'] ) && ! empty( $checkpoint['pre_snapshot_artifacts'] ) ),
            'preserve_orders'  => ! empty( $checkpoint['options']['preserve_orders'] ),
            'woo_order_since'  => isset( $checkpoint['options']['woo_order_since'] ) ? (int) $checkpoint['options']['woo_order_since'] : 0,
        );
        $artifacts = array();
        foreach ( $plan as $entry ) {
            $artifacts[] = array(
                'role'       => $entry['role'],
                'file'       => $entry['file'],
                'size'       => ! empty( $entry['staged_path'] ) && file_exists( $entry['staged_path'] ) ? (int) filesize( $entry['staged_path'] ) : (int) $entry['size'],
                'sha256'     => (string) $entry['sha256'],
                'adopt_only' => ! empty( $entry['adopt_only'] ),
            );
            if ( 'db' !== $options['sub_type'] && 'manifest' === $entry['role'] && (int) $entry['snapshot_id'] === (int) $job->snapshot_id && isset( $entry['walk'] ) && is_array( $entry['walk'] ) ) {
                $needs_unarchived = array_key_exists( 'unarchived', $entry['walk'] ) || array_key_exists( 'unarchived_overflow', $entry['walk'] );
                // A failed refresh leaves cached flags intact; only a fresh answer authorizes deletion.
                if ( $needs_unarchived && ( ! MainWP_Backup_Monitor::refresh_negotiated_capabilities( $website ) || '1' !== (string) MainWP_DB::instance()->get_website_option( $website, 'reconcile_unarchived_support' ) ) ) {
                    unset( $options['reconcile'] );
                    $checkpoint['reconcile_withheld'] = 'unarchived_unsupported';
                } else {
                    $options['reconcile'] = array(
                        'manifest' => $entry['file'],
                        'walk'     => $entry['walk'],
                    );
                }
            }
        }

        $params = array(
            'restore_id'     => (string) $checkpoint['restore_id'],
            'source_prefix'  => '',
            'artifacts_json' => wp_json_encode( $artifacts ),
            'options_json'   => wp_json_encode( $options ),
        );
        if ( null !== $pull_manifest ) {
            $params['pull_manifest_json'] = wp_json_encode( $pull_manifest );
        }

        if ( ! $this->lease_still_held( $job ) ) {
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }
        // A lost create response must remain cancellable even when its task id never lands.
        $checkpoint['child_create_attempted'] = true;
        if ( ! MainWP_Backup_Restore_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_create', $params, false, false, false );

        if ( ! is_array( $payload ) || empty( $payload['task_id'] ) ) {
            $message    = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not accept the restore task.', 'mainwp' );
            $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
            $retryable  = in_array( $error_code, array( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ), true );
            $this->record_failure( $job, $message, $retryable, $error_code );
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }

        $task_id = sanitize_text_field( (string) $payload['task_id'] );
        if ( $this->cancel_task_created_after_terminal( $job, $task_id ) ) {
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }
        $reused = ! empty( $payload['reused'] );
        if ( $reused ) {
            $store  = MainWP_Backup_Restore_Job_Store::instance();
            $phase  = isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
            $status = MainWP_Backup_Restore_Job_Store::STATUS_RUNNING;
            if ( 'receiving' === $phase || ( 'queued' === $phase && empty( $payload['sub_state']['params']['pull_manifest'] ) ) ) {
                $status = null !== $pull_manifest ? MainWP_Backup_Restore_Job_Store::STATUS_STAGING : MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING;
            } elseif ( 'completed' === $phase ) {
                $status                     = MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING;
                $verification               = isset( $payload['sub_state']['verification'] ) && is_array( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
                $checkpoint['verification'] = $this->apply_withheld_reconcile_reason( $verification, $checkpoint );
                if ( isset( $payload['sub_state']['progress']['woo_preserve'] ) ) {
                    $checkpoint['verification']['woo_preserve'] = $payload['sub_state']['progress']['woo_preserve'];
                }
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    $this->cancel_task_created_after_terminal( $job, $task_id );
                    return array(
                        'task_id'       => '',
                        'pull_rejected' => false,
                    );
                }
            }
            if ( 'failed' === $phase ) {
                if ( ! $this->handle_failed_child_task( $job, $payload, $task_id, $checkpoint ) ) {
                    return array(
                        'task_id'       => '',
                        'pull_rejected' => false,
                    );
                }
            } elseif ( ! $store->set_status( (int) $job->id, $status, array( 'child_task_id' => $task_id ), $this->lock_token ) ) {
                $this->cancel_task_created_after_terminal( $job, $task_id );
                return array(
                    'task_id'       => '',
                    'pull_rejected' => false,
                );
            }
        }

        return array(
            'task_id'       => $task_id,
            'pull_rejected' => ! empty( $payload['pull_manifest_rejected'] ),
            'reused'        => $reused,
        );
    }

    /**
     * A replay keeps the first task's params, so only a missing manifest can
     * reflect an earlier withheld request. Other results describe actual work.
     *
     * @param array $verification Completed Child verification.
     * @param array $checkpoint   Checkpoint for the same restore identity.
     * @return array Verification with the withholding reason when applicable.
     */
    private function apply_withheld_reconcile_reason( array $verification, array $checkpoint ): array {
        if ( 'unarchived_unsupported' === ( $checkpoint['reconcile_withheld'] ?? '' ) && ( ! array_key_exists( 'reconcile', $verification ) || ( 'skipped' === ( $verification['reconcile']['status'] ?? '' ) && 'no_manifest' === ( $verification['reconcile']['reason'] ?? '' ) ) ) ) {
            $verification['reconcile'] = array(
                'status' => 'skipped',
                'reason' => 'unarchived_unsupported',
            );
        }
        return $verification;
    }

    /**
     * Handle a failed child task, falling back from cloud pull once.
     *
     * @param object     $job        Restore job.
     * @param array      $payload    Child response.
     * @param string     $task_id    Reused handle to retain if no fallback is needed.
     * @param array|null $checkpoint Live checkpoint, or null to use the job row.
     *
     * @return bool Whether the handle can be returned after recording failure.
     */
    private function handle_failed_child_task( object $job, array $payload, string $task_id = '', ?array $checkpoint = null ): bool {
        $store      = MainWP_Backup_Restore_Job_Store::instance();
        $error_code = isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
        if ( '' === $error_code && isset( $payload['sub_state']['error']['code'] ) ) {
            $error_code = (string) $payload['sub_state']['error']['code'];
        }

        // A failed cloud pull never fails the restore: the job goes
        // back through staging once, taking the standard transfer.
        if ( 'mainwp_cloud_pull_failed' === $error_code && empty( $job->checkpoint['cloud_pull_tried'] ) ) {
            $checkpoint                     = $checkpoint ?? $job->checkpoint;
            $checkpoint['cloud_pull_tried'] = true;
            $checkpoint['transfer']         = array();
            // The child pins each restore_id to its first task for replay safety; the fallback needs its own identity.
            $checkpoint['restore_id'] = strtolower( wp_generate_password( 12, false ) );
            unset( $checkpoint['child_create_attempted'], $checkpoint['reconcile_withheld'] );
            if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => '' ), $this->lock_token ) ) {
                return false;
            }
            if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                return false;
            }
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the direct cloud download failed on the site; retrying with the standard transfer.', (int) $job->id ), (int) $job->site_id, 0 );
            return false;
        }

        $error = isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The restore failed on the child site.', 'mainwp' );
        if ( isset( $payload['sub_state']['error']['message'] ) ) {
            $error = (string) $payload['sub_state']['error']['message'];
        }
        if ( '' !== $task_id && ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $task_id ), $this->lock_token ) ) {
            return false;
        }
        $this->record_failure( $job, $error, false, $error_code );
        return true;
    }

    /**
     * Record terminal failure before releasing ownership and publishing the event.
     *
     * Cancellation decision matrix (rows apply in order):
     * failed_retryable uses resume_status (pending when absent).
     * A child task can still restore in staging after a rejected pull manifest,
     * and in transferring/running. Pending, preflight and pre_snapshot precede
     * restore-task creation; verifying follows confirmed child completion.
     * Terminal rows cannot drive work. A task id can be lost after creation,
     * so an attempted create retains its restore identity for cancellation.
     *
     * Child can restore?            Child answer       Decision
     * terminal row                  not requested      retain terminal verdict
     * advanced in flight            any reply          retain verification or terminal verdict
     * verifying, stored failure     not requested      clear pending decision; poll completion
     * verifying, no stored failure  not requested      terminalize fresh failure; otherwise drive on
     * no                            not requested      terminalize failure or cancellation
     * yes                           ok/failed          terminalize failure or cancellation
     * yes (by restore_id)           not_found, id      cancel that id; decide from its answer
     *                               recorded meanwhile
     * yes                           completed          clear decision; stage if id-less, otherwise run
     * yes                           busy/rolling_back  defer until 15 minutes have elapsed
     * yes                           unconfirmed        defer until the same elapsed-time limit
     * yes                           any deferred       at timeout, terminalize with warning
     *
     * Durability, crossed with every row above:
     * - A stored terminal_failure wins over a later error. An authoritative
     *   completed child outranks both because the child knows the restore happened.
     * - Without an incoming or stored failure, cancel_intent means user
     *   cancellation. A retry record supplies elapsed time, not a verdict.
     *   Missing retry records start now.
     * - A landed terminal write re-reads the row; a task id on it other than the
     *   one this decision requested a cancel for is cancelled by id. The
     *   cloud-pull fallback can replace the id under a live row.
     * - Intent, original failure and retry time must be durable before the child
     *   round trip, so a new lease holder inherits the gate while it is in flight.
     * - A refused combined write defers without a request or cleanup; arm intent
     *   and original failure separately before returning. Existing intent is sticky.
     * - A refused terminal/drive-on write retains ownership and staging.
     * - Only a successful terminal write releases the safety-backup handle and
     *   cleans staging. Defer, drive on and already terminal do neither;
     *   phase_verifying() and the UI cancel lane obey the same ordering.
     *
     * A thrown or unreadable reply is unconfirmed, not proof the child stopped.
     * An answered create error still cancels by restore_id because a Child task may exist.
     * Verification never consults it because child completion settled that already.
     * Poll frequency must not shorten the rollback window or replace the cause.
     *
     * Returns: terminal for an absent, already terminal or foreign-lease row,
     * or a row that reached verification or a terminal status during the request,
     * and for a successful terminal write or an unfenced terminal refusal.
     * Deferred covers refused decision writes, replies within the timeout,
     * refused completion checkpoint writes, fenced terminal refusals and a
     * terminal write that kept losing its compare-and-swap (the UI lane arms
     * the intent first so the next drive finishes it).
     * Drive_on means the completion checkpoint cleared successfully.
     *
     * @param object      $job        Job row.
     * @param string|null $error      Error text, or null for a user cancellation.
     * @param string      $error_code Stable error code, or empty when none.
     * @param string|null $lock_token Captured lease token, or null for an unleased UI cancellation.
     * @param string|null $history_error_code Classification kept separate from restore-facing errors.
     * @param string      $history_error_extra Replay tag and registered transport cause only.
     * @param string|null $cancel_action User's resolved action; null keeps the worker's failure decision.
     *
     * @return string One of the TERMINAL_OUTCOME_* constants, stopped, or checking_changed for a retryable user refusal.
     */
    private function record_terminal_failure( object $job, ?string $error, string $error_code, ?string $lock_token, ?string $history_error_code = null, string $history_error_extra = '', ?string $cancel_action = null ): string {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $job   = $store->get_job( (int) $job->id );
        if ( null === $job || ! in_array( $job->status, $store::active_statuses(), true ) || ( null !== $lock_token && $job->locked_by !== $lock_token ) ) {
            return self::TERMINAL_OUTCOME_TERMINAL;
        }
        $verify_stop = 'stop_checking' === $cancel_action;
        if ( null !== $cancel_action ) {
            $action = self::resolve_cancel_action( $job );
            if ( 'none' === $action ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            if ( $action !== $cancel_action ) {
                return 'checking_changed';
            }
        }

        $failure            = $verify_stop ? array() : ( $job->checkpoint['terminal_failure'] ?? array() );
        $had_stored_failure = ! empty( $failure );
        $error              = $failure['error'] ?? $error;
        $error_code         = $failure['error_code'] ?? $error_code;
        if ( $verify_stop ) {
            $error      = '' !== (string) $job->last_error ? (string) $job->last_error : __( 'The site did not respond after the restore. The restore completed on the child; check the site directly.', 'mainwp' );
            $error_code = (string) ( $job->checkpoint['last_error_code'] ?? '' );
        }
        $phase           = $store::STATUS_FAILED_RETRYABLE === $job->status ? ( $job->checkpoint['resume_status'] ?? $store::STATUS_PENDING ) : $job->status;
        $can_cancel      = ( ! empty( $job->child_task_id ) || ( ! empty( $job->checkpoint['child_create_attempted'] ) && ! empty( $job->checkpoint['restore_id'] ) ) ) && in_array( $phase, array( $store::STATUS_STAGING, $store::STATUS_TRANSFERRING, $store::STATUS_RUNNING ), true );
        $outcome         = $store::STATUS_VERIFYING === $phase ? 'completed' : 'ok';
        $by_restore_id   = $can_cancel && empty( $job->child_task_id );
        $decided_task_id = (string) $job->child_task_id;
        if ( $can_cancel ) {
            $deferred = $store->defer_terminal_failure( (int) $job->id, $error, $error_code, $lock_token );
            if ( null === $deferred ) {
                // A failed combined write must not let the next worker resume a restore slice.
                $failure = null === $error ? array() : array(
                    'error'      => $error,
                    'error_code' => $error_code,
                );
                if ( ! $store->arm_cancel_intent( (int) $job->id, $failure, $lock_token ) ) {
                    do_action( 'mainwp_log_info', sprintf( 'Restore job %d: cancellation intent could not be persisted.', (int) $job->id ), (int) $job->site_id, 0 );
                }
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $outcome = $this->request_child_cancel_or_unconfirmed( $job );
            $fresh   = $store->get_job( (int) $job->id );
            if ( null === $fresh || ! in_array( $fresh->status, $store::active_statuses(), true ) || $store::STATUS_VERIFYING === $fresh->status ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            // A lookup by restore_id that found nothing predates any create still
            // in flight. If the worker recorded a task id meanwhile, that task may
            // run, so the decision comes from a cancel of it by id.
            if ( 'failed' === $outcome && $by_restore_id && ! empty( $fresh->child_task_id ) ) {
                do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site had no task for this restore when the cancel arrived, but task %s was recorded meanwhile; cancelling it by id.', (int) $job->id, $fresh->child_task_id ), (int) $job->site_id, 0 );
                $decided_task_id = (string) $fresh->child_task_id;
                $outcome         = $this->request_child_cancel_or_unconfirmed( $fresh );
                $fresh           = $store->get_job( (int) $job->id );
                if ( null === $fresh || ! in_array( $fresh->status, $store::active_statuses(), true ) || $store::STATUS_VERIFYING === $fresh->status ) {
                    return self::TERMINAL_OUTCOME_TERMINAL;
                }
            }
        }

        if ( in_array( $outcome, array( 'busy', 'rolling_back', 'unconfirmed' ), true ) ) {
            if ( time() - $deferred['first_attempt_at'] < $store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS ) {
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $warning    = esc_html__( 'The child site did not confirm that it stopped restoring. The site may need manual cleanup.', 'mainwp' );
            $error_code = null === $error ? 'mainwp_restore_cancel_unconfirmed' : $error_code;
            $error      = null === $error ? $warning : $error . ' ' . $warning;
        }

        if ( 'completed' === $outcome && ( $can_cancel || null === $error || $had_stored_failure ) ) {
            // A job without a recorded task id cannot poll; staging recovers the
            // id through the idempotent create. Decide from the row re-read after
            // the Child round trip, since the worker may have recorded it meanwhile.
            $unrecorded = $can_cancel && empty( $fresh->child_task_id );
            if ( $can_cancel && ! $store->set_status( (int) $job->id, $unrecorded ? $store::STATUS_STAGING : $store::STATUS_RUNNING, array(), $lock_token ) ) {
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $fresh = $store->get_job( (int) $job->id );
            if ( null === $fresh ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            $checkpoint = $fresh->checkpoint;
            unset( $checkpoint['cancel_confirmation'], $checkpoint['terminal_failure'], $checkpoint['last_error_code'] );
            if ( $unrecorded ) {
                $checkpoint['child_completed_unrecorded'] = true;
            }
            return $store->save_checkpoint( (int) $job->id, $checkpoint, $lock_token, true ) ? self::TERMINAL_OUTCOME_DRIVE_ON : self::TERMINAL_OUTCOME_DEFERRED;
        }

        $cancelled    = null === $error;
        $confirmation = null;
        if ( $can_cancel ) {
            $confirmation = in_array( $outcome, array( 'ok', 'failed' ), true ) ? 'confirmed' : 'unconfirmed';
        } elseif ( $cancelled ) {
            $confirmation = 'not_needed';
        }
        // Terminal status and its stop evidence land together, so no
        // concurrent checkpoint write can separate them.
        $written = $store->finish_with_cancel_confirmation(
            (int) $job->id,
            $cancelled ? $store::STATUS_CANCELLED : $store::STATUS_FAILED_PERMANENT,
            $error,
            $error_code,
            $confirmation,
            $lock_token,
            $verify_stop
        );
        if ( 'written' !== $written ) {
            if ( $verify_stop ) {
                return 'terminal' === $written ? self::TERMINAL_OUTCOME_TERMINAL : 'checking_changed';
            }
            if ( 'contended' === $written && null === $lock_token ) {
                // The UI lane holds no lease to retry with; a durable intent
                // lets the next drive finish the cancel.
                $store->arm_cancel_intent(
                    (int) $job->id,
                    $cancelled ? array() : array(
                        'error'      => $error,
                        'error_code' => $error_code,
                    )
                );
            }
            if ( 'terminal' === $written || ( 'refused' === $written && null === $lock_token ) ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            return self::TERMINAL_OUTCOME_DEFERRED;
        }

        // The terminal write ends every fenced worker write, so an id the row
        // carries now was recorded before the swap. The decision requested a
        // cancel for $decided_task_id only; any other id belongs to a task nothing
        // will cancel: the cloud-pull fallback replaces the id under a live row,
        // and a worker whose write landed does not run its refused-write cancel.
        $final = $store->get_job( (int) $job->id );
        if ( $verify_stop && null !== $final ) {
            $job        = $final;
            $error      = (string) $final->last_error;
            $error_code = (string) ( $final->checkpoint['last_error_code'] ?? '' );
        }
        if ( ! $verify_stop && null !== $final && ! empty( $final->child_task_id ) && (string) $final->child_task_id !== $decided_task_id ) {
            $this->cancel_task_created_after_terminal( $final, (string) $final->child_task_id );
        }

        MainWP_Backup_Run_History::finalize_restore_safety( (int) $job->id, $cancelled ? 'cancelled' : 'failed', $cancelled ? '' : ( $history_error_code ?? (string) $error_code ), $cancelled ? '' : $history_error_extra );

        if ( $cancelled ) {
            MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
            $this->cancel_owned_pre_snapshot_handle( $job );
            return self::TERMINAL_OUTCOME_TERMINAL;
        }

        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
        // The owner is terminal and no other lane may release its handle.
        $website = MainWP_DB::instance()->get_website_by_id( (int) $job->site_id );
        // A deleted site can leave its job behind. Throwing here inside a
        // catch block would prevent the failure event below from firing.
        if ( ! empty( $job->pre_snapshot_task_id ) && ! empty( $website ) ) {
            // Every later phase starts after the child reported the safety
            // backup finished, so only this phase can leave it running.
            if ( $store::STATUS_PRE_SNAPSHOT === $phase ) {
                $this->stop_unrecorded_pre_snapshot( $website, $job );
            } else {
                $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );
            }
        }

        /**
         * Fires after a restore fails permanently (not retryable, or
         * retries exhausted).
         *
         * The logs connector records the event.
         *
         * @param array $event Site, snapshot, job, and error details.
         */
        do_action(
            'mainwp_backup_restore_failed',
            array(
                'site_id'     => (int) $job->site_id,
                'snapshot_id' => (int) $job->snapshot_id,
                'job_id'      => (int) $job->id,
                'error'       => $error,
                'error_code'  => $error_code,
            )
        );
        return $verify_stop ? 'stopped' : self::TERMINAL_OUTCOME_TERMINAL;
    }

    /**
     * Record a failure with backoff; exhausted attempts go permanent.
     *
     * The current status is saved as the resume point so a retry
     * continues the phase that failed instead of starting over.
     *
     * @param object      $job        Job row.
     * @param string      $error      Error text.
     * @param bool        $retryable  Whether a retry can succeed.
     * @param string      $error_code Stable error code matching $error, or '' when none.
     * @param string|null $history_error_code Classification kept separate from restore-facing errors.
     */
    private function record_failure( object $job, string $error, bool $retryable, string $error_code = '', ?string $history_error_code = null ): void {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $error = $this->humanize_stored_error( $error );

        // The phase that failed may have checkpointed progress after this
        // row was read; a stale write here would erase that cursor.
        $fresh      = $store->get_job( (int) $job->id );
        $checkpoint = null !== $fresh ? $fresh->checkpoint : $job->checkpoint;

        $history_error_extra = '';
        $phase               = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status ? ( $checkpoint['resume_status'] ?? MainWP_Backup_Restore_Job_Store::STATUS_PENDING ) : $job->status;
        if ( MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT === $phase
            && ! empty( $checkpoint['pre_snapshot_reconcile_pending'] )
            && ! empty( $checkpoint['pre_snapshot_replay_confirmed'] )
            && ! in_array( $error_code, MainWP_Backup_Error_Codes::get_registered_codes(), true )
            && in_array( $history_error_code, array( MainWP_Backup_Error_Codes::ERR_CONNECT_FAILED, MainWP_Backup_Error_Codes::ERR_HTTP_ERROR, MainWP_Backup_Error_Codes::ERR_TIMEOUT ), true )
        ) {
            // History needs the replay cause; restore clients still consume the original failure.
            $history_error_extra = 'AUTH_ERROR2; ' . $history_error_code;
            $history_error_code  = MainWP_Backup_Error_Codes::ERR_TIMEOUT;
        }

        // A failure that follows real transfer progress starts a fresh
        // attempt budget: MAX_ATTEMPTS bounds consecutive no-progress
        // failures, not the total hiccups a large transfer on a flaky
        // host rides through while still moving forward.
        $advanced = $this->transfer_advanced_since_last_failure( $checkpoint );
        $attempts = $advanced ? 1 : (int) $job->attempt_count + 1;

        if ( ! $retryable || MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            if ( self::TERMINAL_OUTCOME_DEFERRED === $this->record_terminal_failure( $job, $error, $error_code, $this->lock_token, $history_error_code, $history_error_extra ) ) {
                // A deferral with no armed intent means the evidence writes were
                // refused, not that the Child asked for time; back off so the row
                // is not re-driven every tick, and try the terminal write again
                // after the delay.
                $after = $store->get_job( (int) $job->id );
                if ( null !== $after && in_array( $after->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) && empty( $after->checkpoint['cancel_intent'] ) ) {
                    $this->back_off_in_place( $job, $error, $error_code, false );
                }
            }
            return;
        }

        $checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status && isset( $checkpoint['resume_status'] )
            ? (string) $checkpoint['resume_status']
            : (string) $job->status;
        // Keep the code in step with last_error so the progress poll can carry
        // a stable machine-readable code for support and AI diagnosis.
        $checkpoint['last_error_code']       = $error_code;
        $checkpoint['last_failure_transfer'] = $this->transfer_position( $checkpoint );
        $next_retry_at                       = time() + MainWP_Backup_Backoff::delay_for_attempt( $attempts );
        if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
            $this->back_off_in_place( $job, $error, $error_code, true );
            return;
        }

        if ( ! $store->fail_retryable( (int) $job->id, $error, $attempts, $next_retry_at, $this->lock_token ) ) {
            // The lease was reclaimed; the live holder owns the retry
            // bookkeeping, so the diag event below is not emitted here.
            return;
        }

        /**
         * Fires after a restore failure is recorded as retryable, so the
         * diagnostics recorder can capture attempt boundaries instead of
         * leaving them to be inferred from stream gaps.
         *
         * @param int    $job_id         Restore job id.
         * @param int    $site_id        Child site id.
         * @param int    $attempts       Attempt count after this failure.
         * @param string $error_code     Stable error code, '' when none.
         * @param int    $next_retry_at  Epoch the backoff releases the job.
         * @param bool   $progress_reset Whether transfer progress reset the budget.
         */
        do_action( 'mainwp_backup_diag_restore_retry', (int) $job->id, (int) $job->site_id, $attempts, $error_code, $next_retry_at, $advanced );
    }

    /**
     * Record a failure's backoff on the row without moving its phase.
     *
     * @param object $job         Job row.
     * @param string $error       Error text.
     * @param string $error_code  Stable error code, or '' when none.
     * @param bool   $terminalize Whether exhausted attempts end the job here.
     */
    private function back_off_in_place( object $job, string $error, string $error_code, bool $terminalize ): void {
        if ( ! $this->lease_still_held( $job ) ) {
            return; // The live holder owns the retry bookkeeping.
        }
        $store = MainWP_Backup_Restore_Job_Store::instance();
        // A retry without its saved resume point would restart at pending,
        // taking a second safety backup over the restored site. Keep the
        // phase and back off in place: acquisition waits for next_retry_at
        // in every active status, and attempts still run out into a
        // terminal failure.
        $current = $store->get_job( (int) $job->id );
        if ( null === $current || ! in_array( $current->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) ) {
            return;
        }
        // The watermark that reset the budget did not persist, so the next
        // failure would reset it again; count from the stored attempt instead.
        $attempts      = (int) $current->attempt_count + 1;
        $next_retry_at = time() + MainWP_Backup_Backoff::delay_for_attempt( $attempts );
        $kept          = $store->set_status(
            (int) $job->id,
            $current->status,
            array(
                'last_error'    => $error,
                'attempt_count' => $attempts,
                'next_retry_at' => $next_retry_at,
            ),
            $this->lock_token
        );
        if ( ! $kept ) {
            return;
        }
        /** This action is documented in class/backup/class-mainwp-backup-restore-orchestrator.php */
        do_action( 'mainwp_backup_diag_restore_retry', (int) $job->id, (int) $job->site_id, $attempts, $error_code, $next_retry_at, false );
        if ( $terminalize && MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            // Backoff first: a terminal write refused by the same failure
            // leaves a backed-off row, not one re-driven every tick.
            $this->record_terminal_failure( $job, $error, $error_code, $this->lock_token );
        }
    }

    /**
     * Replace the connect layer's raw token messages with sentences a
     * person can act on. Every stored last_error funnels through
     * record_failure, so this is the single choke point - transfer
     * failures reach it through send_chunk's catch and never touch the
     * process_pending catch. Diagnostics events keep the raw detail; only
     * the stored, user-facing message is rewritten.
     *
     * @param string $error Raw error text.
     *
     * @return string Humanized error text.
     */
    private function humanize_stored_error( string $error ): string {
        if ( 0 === strpos( $error, 'HTTPERROR' ) ) {
            return esc_html__( 'The site could not be reached during the restore. It may be down, overloaded, or briefly blocking dashboard requests; the restore retries automatically.', 'mainwp' );
        }
        if ( 0 === strpos( $error, 'NOMAINWP' ) ) {
            return esc_html__( 'The MainWP Child plugin did not answer on the site. Check that the site loads and the MainWP Child plugin is active; the restore retries automatically.', 'mainwp' );
        }
        if ( 0 === strpos( $error, 'WPERROR' ) ) {
            return esc_html__( 'The site returned an unexpected response during the restore; the restore retries automatically.', 'mainwp' );
        }
        return $error;
    }

    /**
     * The transfer cursor's position as an (artifact_index, offset) pair.
     *
     * @param array $checkpoint Job checkpoint.
     *
     * @return array { artifact_index: int, offset: int }
     */
    private function transfer_position( array $checkpoint ): array {
        $cursor = isset( $checkpoint['transfer'] ) ? (array) $checkpoint['transfer'] : array();
        return array(
            'artifact_index' => isset( $cursor['artifact_index'] ) ? (int) $cursor['artifact_index'] : 0,
            'offset'         => isset( $cursor['offset'] ) ? (int) $cursor['offset'] : 0,
        );
    }

    /**
     * Whether the transfer cursor moved forward since the last recorded
     * failure. Compared as an (artifact_index, offset) tuple because the
     * offset resets to zero when the index advances. Phases that never
     * touch the cursor leave it equal to the watermark, so they keep the
     * plain counting behavior.
     *
     * @param array $checkpoint Job checkpoint.
     *
     * @return bool
     */
    private function transfer_advanced_since_last_failure( array $checkpoint ): bool {
        if ( ! isset( $checkpoint['last_failure_transfer'] ) ) {
            return false;
        }
        $mark        = (array) $checkpoint['last_failure_transfer'];
        $mark_index  = isset( $mark['artifact_index'] ) ? (int) $mark['artifact_index'] : 0;
        $mark_offset = isset( $mark['offset'] ) ? (int) $mark['offset'] : 0;
        $now         = $this->transfer_position( $checkpoint );

        return $now['artifact_index'] > $mark_index
            || ( $now['artifact_index'] === $mark_index && $now['offset'] > $mark_offset );
    }

    /**
     * Best-effort retirement of the safety backups earlier restores of this
     * site kept for rollback.
     *
     * Runs once a restore's own safety backup is confirmed and again when a
     * restore completes, so the child keeps one per site whatever the
     * earlier restores' outcomes, and an earlier prune the child did not
     * confirm is retried. A safety backup that a failed rollback can still
     * retry from stays.
     *
     * The child reports counts, not per-task outcomes, so each task goes in
     * its own request. Only a task the child pruned or no longer has counts as
     * removed; an active task, an unreadable answer or an unreachable site
     * leaves the row for the next pass to retry.
     *
     * @param object $job Job that holds the site.
     */
    private function prune_superseded_pre_snapshots( object $job ): void {
        $statuses = array(
            MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED,
            MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT,
            MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED,
        );
        global $wpdb;
        $earlier = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $wpdb->prepare(
                'SELECT id, site_id, status, pre_snapshot_task_id, checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND id < %d AND status IN ( ' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' ) AND pre_snapshot_task_id <> %s AND checkpoint NOT LIKE %s ORDER BY id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from trusted DB helper; one placeholder per status.
                array_merge(
                    array( (int) $job->site_id, (int) $job->id ),
                    $statuses,
                    array( '', '%' . $wpdb->esc_like( '"pre_snapshot_pruned":true' ) . '%' )
                )
            )
        );
        if ( empty( $earlier ) ) {
            return;
        }

        try {
            $website = $this->website( $job );
        } catch ( \Throwable $e ) {
            return;
        }

        foreach ( $earlier as $row ) {
            $checkpoint = json_decode( (string) $row->checkpoint, true );
            $checkpoint = is_array( $checkpoint ) ? $checkpoint : array();
            if ( $this->safety_backup_held_by_rollback( $row, $checkpoint ) ) {
                continue;
            }
            if ( null === $this->prune_safety_backup( $website, (int) $row->id, (string) $row->pre_snapshot_task_id, $checkpoint ) ) {
                // Best effort: an unreachable site will not answer the next task either.
                return;
            }
        }
    }

    /**
     * Retire the safety backup a completing rollback restored from.
     *
     * A retried rollback points at the failed rollback before it, so the
     * chain is walked back to the restore that took the safety backup. The
     * completing rollback is the only one left that could restore from it,
     * so no rollback-hold check applies.
     *
     * @param object $job        Rollback job that is completing.
     * @param array  $checkpoint Its checkpoint.
     */
    private function retire_rollback_source( object $job, array $checkpoint ): void {
        $store  = MainWP_Backup_Restore_Job_Store::instance();
        $source = null;
        $seen   = array( (int) $job->id => true );
        $next   = (int) ( $checkpoint['rollback_of'] ?? 0 );
        while ( $next > 0 && ! isset( $seen[ $next ] ) ) {
            $seen[ $next ] = true;
            $candidate     = $store->get_job( $next );
            if ( null === $candidate || (int) $candidate->site_id !== (int) $job->site_id ) {
                return;
            }
            if ( empty( $candidate->checkpoint['is_rollback'] ) ) {
                $source = $candidate;
                break;
            }
            $next = (int) ( $candidate->checkpoint['rollback_of'] ?? 0 );
        }
        if ( null === $source || '' === (string) $source->pre_snapshot_task_id || ! empty( $source->checkpoint['pre_snapshot_pruned'] ) ) {
            return;
        }

        try {
            $website = $this->website( $job );
        } catch ( \Throwable $e ) {
            return;
        }
        $this->prune_safety_backup( $website, (int) $source->id, (string) $source->pre_snapshot_task_id, (array) $source->checkpoint );
    }

    /**
     * Ask the child to prune one safety-backup task and record a confirmed
     * removal on the restore job that took it.
     *
     * The job is marked as pruning before the request, so a confirmed
     * removal whose record cannot be written still stops the job offering
     * Roll Back from the deleted files. An unconfirmed answer clears the
     * mark again; if even that write fails, Roll Back stays off, which is
     * the safe side.
     *
     * @param object $website    Website object.
     * @param int    $job_id     Restore job that took the safety backup.
     * @param string $task_id    Its pre-snapshot task id.
     * @param array  $checkpoint That job's decoded checkpoint.
     *
     * @return bool|null Whether the child confirmed the removal, or null when
     *                   the request threw.
     */
    private function prune_safety_backup( object $website, int $job_id, string $task_id, array $checkpoint ): ?bool {
        $store                              = MainWP_Backup_Restore_Job_Store::instance();
        $checkpoint['pre_snapshot_pruning'] = true;
        if ( ! $store->save_checkpoint( $job_id, $checkpoint ) ) {
            return false;
        }
        unset( $checkpoint['pre_snapshot_pruning'] );

        try {
            $answer = MainWP_Connect::fetch_url_authed(
                $website,
                'backup_task_prune',
                array(
                    'task_ids' => wp_json_encode( array( $task_id ) ),
                    'sweep'    => 0,
                ),
                false,
                false,
                false
            );
        } catch ( \Throwable $e ) {
            $store->save_checkpoint( $job_id, $checkpoint );
            return null;
        }

        $removed = MainWP_Backup_Executor::child_prune_confirmed( $answer );
        if ( $removed ) {
            $checkpoint['pre_snapshot_pruned'] = true;
        }
        if ( ! $store->save_checkpoint( $job_id, $checkpoint ) ) {
            $store->save_checkpoint( $job_id, $checkpoint );
        }
        return $removed;
    }

    /**
     * The artifacts a completed safety backup reported, when they can serve
     * as a rollback source.
     *
     * The answer must be about the task this job started, carry no error,
     * name only plain files, and include the full archive itself (the role
     * the Child gives a full backup's archive) with a size above zero. A
     * manifest alone cannot restore the site, so anything else is an
     * unusable safety backup.
     *
     * @param mixed  $payload Child response to backup_task_run.
     * @param string $task_id Safety-backup task id this job started.
     *
     * @return array Artifact entries, or empty when the answer is unusable.
     */
    private function safety_backup_artifacts( $payload, string $task_id ): array {
        if ( ! is_array( $payload ) || array_key_exists( 'error', $payload ) || '' === $task_id || ! isset( $payload['task_id'] ) || ! is_string( $payload['task_id'] ) || $payload['task_id'] !== $task_id ) {
            return array();
        }

        $artifacts = array();
        if ( ! empty( $payload['artifacts'] ) && is_array( $payload['artifacts'] ) ) {
            $artifacts = array_values( $payload['artifacts'] );
        } elseif ( ! empty( $payload['file'] ) ) {
            $artifacts[] = array(
                'role' => 'full',
                'file' => (string) $payload['file'],
                'size' => isset( $payload['size'] ) ? (int) $payload['size'] : 0,
            );
        }

        $has_archive = false;
        foreach ( $artifacts as $artifact ) {
            $file = is_array( $artifact ) && isset( $artifact['file'] ) && is_string( $artifact['file'] ) ? $artifact['file'] : '';
            if ( '' === $file || basename( $file ) !== $file || in_array( $file, array( '.', '..' ), true ) ) {
                return array();
            }
            if ( 'full' === ( $artifact['role'] ?? null ) && isset( $artifact['size'] ) && is_numeric( $artifact['size'] ) && (int) $artifact['size'] > 0 ) {
                $has_archive = true;
            }
        }
        return $has_archive ? $artifacts : array();
    }

    /**
     * Stop a safety backup that is still running when its restore ends.
     *
     * The handle is released when the child reports the safety backup
     * finished, so a handle this job still owns means the Dashboard never
     * recorded it. Without a recorded artifact no rollback can use it, so a
     * task the child already finished is pruned instead. Best effort: the
     * handle is released whatever the child answers.
     *
     * @param object $website Website object.
     * @param object $job     Restore job that just ended.
     */
    private function stop_unrecorded_pre_snapshot( object $website, object $job ): void {
        $task_id = (string) $job->pre_snapshot_task_id;
        $owner   = (string) $job->id;
        $handle  = json_decode( (string) MainWP_DB::instance()->get_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true );
        $owned   = is_array( $handle ) && isset( $handle['task_id'], $handle['owner'] ) && is_scalar( $handle['task_id'] ) && is_scalar( $handle['owner'] )
            && (string) $handle['task_id'] === $task_id && (string) $handle['owner'] === $owner;
        if ( ! $owned ) {
            return;
        }

        $answer = null;
        try {
            $answer = MainWP_Backup_Executor::send_backup_task_cancel( $website, (string) $task_id );
        } catch ( \Throwable $e ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its safety backup: %s', (int) $job->id, $e->getMessage() ), (int) $job->site_id, 0 );
        } finally {
            $this->release_pre_snapshot_handle( $website, $task_id, $owner );
        }

        if ( is_array( $answer ) && 'already_terminal' === ( $answer['result'] ?? '' ) && empty( $job->checkpoint['pre_snapshot_artifacts'] ) ) {
            // The failure path stamped its error code after $job was read.
            $fresh = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
            $this->prune_safety_backup( $website, (int) $job->id, $task_id, (array) ( null !== $fresh ? $fresh->checkpoint : $job->checkpoint ) );
        }
    }

    /**
     * Whether a rollback of this completed restore still restores from its
     * safety backup.
     *
     * A rollback copies the safety-backup artifacts into its own checkpoint,
     * and a retried rollback copies them again, so the newest rollback that
     * carries the same files owns them. While that rollback can still run or
     * be retried, removing the files would break it.
     *
     * @param object $row        Completed restore row.
     * @param array  $checkpoint Its decoded checkpoint.
     *
     * @return bool
     */
    private function safety_backup_held_by_rollback( object $row, array $checkpoint ): bool {
        $files = array_column( (array) ( $checkpoint['pre_snapshot_artifacts'] ?? array() ), 'file' );
        if ( empty( $files ) ) {
            return false;
        }

        global $wpdb;
        $rollbacks = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $wpdb->prepare(
                'SELECT id, site_id, status, checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND id > %d AND checkpoint LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                (int) $row->site_id,
                (int) $row->id,
                '%' . $wpdb->esc_like( '"is_rollback":true' ) . '%'
            )
        );
        foreach ( (array) $rollbacks as $rollback ) {
            $rollback->checkpoint = json_decode( (string) $rollback->checkpoint, true );
            if ( ! is_array( $rollback->checkpoint ) ) {
                continue;
            }
            $rollback_files = array_column( (array) ( $rollback->checkpoint['pre_snapshot_artifacts'] ?? array() ), 'file' );
            if ( $rollback_files === $files && $this->has_rollback_source( $rollback ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * External reachability check (test seam).
     *
     * A false result is retried with backoff by phase_verifying() until the attempt cap.
     * Certificate verification follows the site's verify_certificate setting because
     * this probe decides the restore's verdict, so a broken certificate must not pass.
     * Cache bypass is best effort: caches ignoring query strings and fatals sent with HTTP 200 still pass.
     *
     * @param object $website Website object.
     *
     * @return bool True when the site answers with a non-server-error.
     */
    protected function site_responds( object $website ): bool {
        $response = wp_remote_get(
            add_query_arg( 'mots', (int) ( microtime( true ) * 1000000 ), $website->url ),
            array(
                'timeout'   => 15,
                'sslverify' => static::ssl_verify_for( $website ),
                'headers'   => array(
                    'Cache-Control' => 'no-cache, no-store, max-age=0',
                    'Pragma'        => 'no-cache',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 500;
    }

    /**
     * Resolve the sslverify argument for a site, mirroring fetch_url()
     * semantics: 1 verifies, 0 does not, and 2 (or an unset value) defers to
     * the dashboard-wide mainwp_sslVerifyCertificate option, which verifies
     * unless it was explicitly turned off.
     *
     * @param object $website Website object.
     *
     * @return bool Whether to verify the certificate.
     */
    protected static function ssl_verify_for( object $website ): bool {
        $verify = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : 2;
        if ( 1 === $verify ) {
            return true;
        }
        if ( 0 === $verify ) {
            return false;
        }
        return false === get_option( 'mainwp_sslVerifyCertificate' ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate' );
    }

    /**
     * Resolve the job's website row.
     *
     * @param object $job Job row.
     *
     * @throws MainWP_Exception When the site no longer exists.
     *
     * @return object Website.
     */
    private function website( object $job ): object {
        $website = MainWP_DB::instance()->get_website_by_id( (int) $job->site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }
        return $website;
    }
}
