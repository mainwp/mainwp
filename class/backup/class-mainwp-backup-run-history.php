<?php
/**
 * Durable per-site backup outcomes.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Keeps backup evidence after task progress is replaced by the next run.
 */
class MainWP_Backup_Run_History {

    /**
     * Request provenance, temporarily replaced by Operations callers.
     *
     * @var array
     */
    private static $context = array(
        'trigger' => '',
        'user_id' => 0,
    );

    /**
     * Bind retention to the existing native backup maintenance lane.
     */
    public static function init(): void {
        add_action( 'mainwp_cronbackups_prune_action', array( self::class, 'prune' ) );
    }

    /**
     * Returns the previous context so nested callers can restore provenance.
     *
     * @param string $trigger Empty clears explicit provenance.
     * @param int    $user_id Initiating Dashboard user.
     * @return array Previous context.
     */
    public static function set_context( string $trigger, int $user_id ): array {
        $previous      = self::$context;
        self::$context = array(
            'trigger' => in_array( $trigger, array( 'schedule', 'manual', 'ability', 'update_gate', 'restore_safety' ), true ) ? $trigger : '',
            'user_id' => max( 0, $user_id ),
        );
        return $previous;
    }

    /**
     * Table identity comes from the Dashboard prefix, never a read filter.
     *
     * @return string
     */
    private static function table_name(): string {
        return MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
    }

    /**
     * A missing history table must not interrupt the backup it describes.
     *
     * @param callable $write Database operation.
     * @param string   $operation Fixed diagnostic label.
     * @return mixed Database result, or false on failure.
     */
    private static function write( callable $write, string $operation ) {
        global $wpdb;
        $suppress = $wpdb->suppress_errors();
        try {
            $result = $write();
            if ( false !== $result ) {
                return $result;
            }
        } catch ( \Throwable $e ) {
            // Database adapters and logging listeners can throw during recovery.
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
        try {
            MainWP_Logger::instance()->warning( 'Backup run history write failed: ' . $operation );
        } catch ( \Throwable $e ) {
            // Logging shares the database that may have refused this write.
        }
        return false;
    }

    /**
     * Records only work that has acquired its run or Child task identity.
     *
     * @param int         $task_id Scheduled or hidden task, zero for ad-hoc work.
     * @param int         $site_id Site in the claimed run.
     * @param int         $started Run identity timestamp.
     * @param string      $trigger Explicit lane, or request/cron provenance.
     * @param int         $owner Task owner for cron without request context.
     * @param int|null    $restore_job_id Safety backup owner.
     * @param string|null $ref Child task identity for ad-hoc work.
     * @return int Row identity, zero when history could not be written.
     */
    public static function start( int $task_id, int $site_id, int $started, string $trigger = '', int $owner = 0, ?int $restore_job_id = null, ?string $ref = null ): int {
        return (int) self::write(
            static function () use ( $task_id, $site_id, $started, $trigger, $owner, $restore_job_id, $ref ) {
                global $wpdb;
                if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
                    return 0;
                }
                $user_id = get_current_user_id();
                if ( '' === $trigger ) {
                    $trigger = self::$context['trigger'];
                    $user_id = self::$context['user_id'];
                    if ( '' === $trigger ) {
                        $cron    = defined( 'DOING_CRON' ) && DOING_CRON;
                        $trigger = $cron ? 'schedule' : 'manual';
                        $user_id = $cron ? $owner : get_current_user_id();
                    }
                } elseif ( in_array( $trigger, array( 'update_gate', 'restore_safety' ), true ) ) {
                    // System lanes have no durable initiating user to attribute.
                    $user_id = 0;
                }
                if ( ! in_array( $trigger, array( 'schedule', 'manual', 'ability', 'update_gate', 'restore_safety' ), true ) ) {
                    return 0;
                }
                $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- durable history writer.
                    self::table_name(),
                    array(
                        'task_id'           => $task_id,
                        'site_id'           => $site_id,
                        'started'           => $started,
                        'trigger_source'    => $trigger,
                        'initiator_user_id' => $user_id,
                        'outcome'           => 'running',
                        'message'           => '',
                        'restore_job_id'    => 'restore_safety' === $trigger ? $restore_job_id : null,
                        'ref'               => in_array( $trigger, array( 'update_gate', 'restore_safety' ), true ) ? $ref : null,
                    ),
                    array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
                );
                return false === $inserted ? false : (int) $wpdb->insert_id;
            },
            'start'
        );
    }

    /**
     * An override has no backup run to finalize, but must keep the initiating user.
     *
     * @param int         $site_id Site admitted by the override.
     * @param string      $verdict Gate evidence at dispatch time.
     * @param string|null $ref Queued update identity, null for immediate updates.
     * @return int Row identity, zero when history could not be written.
     */
    public static function record_gate_override( int $site_id, string $verdict, ?string $ref = null ): int {
        $messages = array(
            'missing'         => 'backup_missing',
            'pending_offsite' => 'backup_pending_offsite',
        );
        if ( ! isset( $messages[ $verdict ] ) || ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return 0;
        }
        return (int) self::write(
            static function () use ( $site_id, $verdict, $ref, $messages ) {
                global $wpdb;
                $now      = time();
                $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- durable override evidence uses the same contained writer as backup runs.
                    self::table_name(),
                    array(
                        'task_id'           => 0,
                        'site_id'           => $site_id,
                        'started'           => $now,
                        'finished'          => $now,
                        'trigger_source'    => 'ability',
                        'initiator_user_id' => get_current_user_id(),
                        'outcome'           => 'gate_overridden',
                        'error_code'        => '',
                        'message'           => $messages[ $verdict ],
                        'ref'               => $ref,
                    ),
                    array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
                );
                return false === $inserted ? false : (int) $wpdb->insert_id;
            },
            'gate override'
        );
    }

    /**
     * Keep newer Child errors in history without changing transport classification.
     *
     * @param \Throwable $error Request or Child failure.
     * @return array Error code and sanitized Child text or replay context.
     */
    public static function error_context( \Throwable $error ): array {
        $code  = MainWP_Backup_Error_Codes::code_for_exception( $error );
        $extra = $error instanceof MainWP_Exception ? (string) $error->get_message_extra() : '';
        $child = $error instanceof MainWP_Exception ? (string) $error->get_message_error_code() : '';
        if ( MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR === $code && preg_match( '/^mainwp_[a-z0-9_]{1,57}$/D', $child ) && MainWP_Backup_Operations::safe_error( $child )['code'] !== $child ) {
            $safe = self::history_error( $child, wp_specialchars_decode( MainWP_Error_Helper::get_error_message( $error ), ENT_QUOTES ) );
            return array(
                'code'  => $safe['code'],
                'extra' => $safe['message'],
            );
        }
        return array(
            'code'  => $code,
            'extra' => preg_match( '/^AUTH_ERROR2(?:; mainwp_[a-z0-9_]+)?$/', $extra ) ? $extra : '',
        );
    }

    /**
     * The first terminal observation wins, even if a later poll disagrees.
     *
     * @param int      $id History identity.
     * @param string   $outcome Terminal outcome.
     * @param string   $error_code Registered, house or unregistered Child code.
     * @param int|null $snapshot_id Snapshot belonging to this run.
     * @param string   $error_extra Sanitized Child text or replay tag and registered transport cause.
     * @return bool Whether a running row changed.
     */
    public static function finalize( int $id, string $outcome, string $error_code = '', ?int $snapshot_id = null, string $error_extra = '' ): bool {
        if ( ! in_array( $outcome, array( 'completed', 'failed', 'cancelled', 'reset', 'incomplete' ), true ) ) {
            return false;
        }
        return (bool) self::write(
            static function () use ( $id, $outcome, $error_code, $snapshot_id, $error_extra ) {
                global $wpdb;
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT trigger_source FROM ' . self::table_name() . ' WHERE id = %d AND outcome = %s', $id, 'running' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table; values prepared.
                if ( '' !== $wpdb->last_error ) {
                    return false;
                }
                if ( ! $row ) {
                    return 0;
                }
                $safe    = self::history_error( $error_code, $error_extra );
                $failure = 'failed' === $outcome || '' !== $error_code;
                $message = $failure ? $safe['message'] : '';
                if ( 'completed' === $outcome && 'update_gate' === $row->trigger_source ) {
                    $message = 'Pre-update backup downloaded; no snapshot recorded.';
                }
                return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- outcome guard makes repeated finalizers harmless.
                    self::table_name(),
                    array(
                        'finished'    => time(),
                        'outcome'     => $outcome,
                        'error_code'  => $failure ? $safe['code'] : '',
                        'message'     => $message,
                        'snapshot_id' => 'completed' === $outcome ? $snapshot_id : null,
                    ),
                    array(
                        'id'      => $id,
                        'outcome' => 'running',
                    ),
                    array( '%d', '%s', '%s', '%s', '%d' ),
                    array( '%d', '%s' )
                );
            },
            'finalize'
        );
    }

    /**
     * Use fixed messages for known codes and cleaned Child text for unknown codes.
     *
     * @param string $code Primary error code.
     * @param string $extra Exception context or an older stored message.
     * @return array Safe code and message.
     */
    private static function history_error( string $code, string $extra ): array {
        $safe = MainWP_Backup_Operations::safe_error( $code );
        if ( $safe['code'] !== $code && preg_match( '/^mainwp_[a-z0-9_]{1,57}$/D', $code ) ) {
            $message = trim( MainWP_Backup_Error_Codes::sanitize_message( $extra ) );
            if ( '' !== $message ) {
                return array(
                    'code'    => $code,
                    'message' => mb_substr( $message, 0, 2000 ),
                );
            }
        }
        if ( MainWP_Backup_Error_Codes::ERR_TIMEOUT === $safe['code'] && preg_match( '/\bAUTH_ERROR2(?:; (mainwp_[a-z0-9_]+))?\)?$/', $extra, $match ) ) {
            $cause            = isset( $match[1] ) && in_array( $match[1], MainWP_Backup_Error_Codes::get_registered_codes(), true ) ? '; ' . $match[1] : '';
            $safe['message'] .= ' (AUTH_ERROR2' . $cause . ')';
        }
        return $safe;
    }

    /**
     * Restrict a late finalizer to the run it observed.
     *
     * @param int      $task_id Task identity.
     * @param int      $started Claimed run timestamp.
     * @param string   $outcome Terminal outcome.
     * @param int|null $site_id Null closes remaining sites in the run.
     * @param string   $error_code Safe formatter input.
     * @param int|null $snapshot_id This site's snapshot.
     * @param string   $error_extra Sanitized Child text or replay tag and registered transport cause.
     */
    public static function finalize_run( int $task_id, int $started, string $outcome, ?int $site_id = null, string $error_code = '', ?int $snapshot_id = null, string $error_extra = '' ): void {
        $where  = 'task_id = %d AND started = %d';
        $values = array( $task_id, $started );
        if ( null !== $site_id ) {
            $where   .= ' AND site_id = %d';
            $values[] = $site_id;
        }
        self::finalize_matching( $where, $values, $outcome, $error_code, $snapshot_id, $error_extra );
    }

    /**
     * Superseded runs can no longer receive per-site terminal observations.
     *
     * @param int $task_id Task identity.
     * @param int $started Newly claimed run timestamp.
     */
    public static function finalize_superseded( int $task_id, int $started ): void {
        self::finalize_matching( 'task_id = %d AND started < %d', array( $task_id, $started ), 'incomplete', '' );
    }

    /**
     * A restore can end before it stores its safety backup's Child handle, so
     * the restore job, which each safety row carries, is the stable key.
     *
     * @param int    $restore_job_id Restore job that owns the safety backup.
     * @param string $outcome Terminal outcome.
     * @param string $error_code Safe formatter input.
     * @param string $error_extra Replay tag and registered transport cause only; empty uses the checkpoint.
     */
    public static function finalize_restore_safety( int $restore_job_id, string $outcome, string $error_code = '', string $error_extra = '' ): void {
        self::write(
            static function () use ( $restore_job_id, $outcome, $error_code, $error_extra ) {
                $extra = '';
                if ( 'failed' === $outcome ) {
                    $job        = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
                    $extra      = '' !== $error_extra ? $error_extra : (string) ( $job->checkpoint['last_error_extra'] ?? '' );
                    $error_code = MainWP_Backup_Error_Codes::code_for_exception( new MainWP_Exception( (string) ( $job->last_error ?? '' ), $extra, $error_code ) );
                }
                self::finalize_matching( 'restore_job_id = %d AND trigger_source = %s', array( $restore_job_id, 'restore_safety' ), $outcome, $error_code, null, $extra );
                return true;
            },
            'finalize_restore_safety'
        );
    }

    /**
     * Ad-hoc lanes have a Child handle instead of a Dashboard task run.
     *
     * @param int    $site_id Site identity.
     * @param string $ref Child task identity.
     * @param string $trigger Lane identity.
     * @param string $outcome Terminal outcome.
     * @param string $error_code Safe formatter input.
     * @param string $error_extra Sanitized Child text or replay tag and registered transport cause.
     */
    public static function finalize_ref( int $site_id, string $ref, string $trigger, string $outcome, string $error_code = '', string $error_extra = '' ): void {
        self::finalize_matching( 'site_id = %d AND ref = %s AND trigger_source = %s', array( $site_id, $ref, $trigger ), $outcome, $error_code, null, $error_extra );
    }

    /**
     * Building and running the lookup sit inside the same containment as the
     * write, so no part of a history update can interrupt the lane it records.
     *
     * @param string   $where Fixed identity clauses with placeholders.
     * @param array    $values One value per placeholder.
     * @param string   $outcome Terminal outcome.
     * @param string   $error_code Safe formatter input.
     * @param int|null $snapshot_id This site's snapshot.
     * @param string   $error_extra Sanitized Child text or replay tag and registered transport cause.
     */
    private static function finalize_matching( string $where, array $values, string $outcome, string $error_code, ?int $snapshot_id = null, string $error_extra = '' ): void {
        self::write(
            static function () use ( $where, $values, $outcome, $error_code, $snapshot_id, $error_extra ) {
                global $wpdb;
                $values[] = 'running';
                // nosemgrep: AIK_tainted-sql-string -- Identity clauses are fixed within this class; every value is prepared.
                $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE ' . $where . ' AND outcome = %s', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table and clauses; every value prepared.
                if ( '' !== $wpdb->last_error ) {
                    return false;
                }
                foreach ( $ids as $id ) {
                    self::finalize( (int) $id, $outcome, $error_code, $snapshot_id, $error_extra );
                }
                return true;
            },
            'find running rows'
        );
    }

    /**
     * Bound maintenance work so history cannot monopolize the cron lane.
     */
    public static function prune(): void {
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return;
        }
        $cutoff   = time() - 90 * DAY_IN_SECONDS;
        $deadline = microtime( true ) + 30;
        do {
            $deleted = self::write(
                static function () use ( $cutoff ) {
                    global $wpdb;
                    return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE (finished > %d AND finished < %d) OR (outcome = %s AND started < %d) LIMIT %d', 0, $cutoff, 'running', $cutoff, 1000 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table; values prepared.
                },
                'prune'
            );
        } while ( 1000 === $deleted && microtime( true ) < $deadline );
    }

    /**
     * Scope counts and pagination before any history leaves the database.
     *
     * @param array $filters Site, task, outcome, trigger and days filters.
     * @param int   $page One-based page.
     * @param int   $per_page Page size.
     * @param int[] $authorized_site_ids Fully authorized site roster.
     * @throws \RuntimeException When either history query fails.
     * @return array Items and total.
     */
    public static function query( array $filters, int $page, int $per_page, array $authorized_site_ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( $authorized_site_ids, static fn( $id ) => is_int( $id ) && $id > 0 ) ) );
        if ( empty( $ids ) ) {
            return array(
                'items' => array(),
                'total' => 0,
            );
        }
        $where  = 'site_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND started >= %d';
        $values = array_merge( $ids, array( time() - min( 90, max( 1, (int) ( $filters['days'] ?? 30 ) ) ) * DAY_IN_SECONDS ) );
        foreach ( array(
            'site_id' => 'site_id',
            'task_id' => 'task_id',
            'outcome' => 'outcome',
            'trigger' => 'trigger_source',
        ) as $key => $column ) {
            if ( isset( $filters[ $key ] ) ) {
                $where   .= ' AND ' . $column . ( in_array( $key, array( 'site_id', 'task_id' ), true ) ? ' = %d' : ' = %s' );
                $values[] = $filters[ $key ];
            }
        }
        $table = self::table_name();
        $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders and replacements are built together from fixed filters.
        if ( null === $total || '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Backup history could not be read.' );
        }
        $total    = (int) $total;
        $per_page = min( 100, max( 1, $per_page ) );
        $page     = max( 1, $page );
        // An overflowing offset can wrap to zero in SQL and return the first page.
        if ( $page - 1 > intdiv( PHP_INT_MAX, $per_page ) ) {
            return array(
                'items' => array(),
                'total' => $total,
            );
        }
        $values[] = $per_page;
        $values[] = ( $page - 1 ) * $per_page;
        $items    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY started DESC, id DESC LIMIT %d OFFSET %d', $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders and replacements are built together from fixed filters.
        if ( ! is_array( $items ) || '' !== $wpdb->last_error ) {
            throw new \RuntimeException( 'Backup history could not be read.' );
        }
        foreach ( $items as &$item ) {
            $item['error']    = '' !== $item['error_code'] ? self::history_error( $item['error_code'], $item['message'] ) : null;
            $override_message = 'gate_overridden' === $item['outcome'] && in_array( $item['message'], array( 'backup_missing', 'backup_pending_offsite' ), true ) ? $item['message'] : '';
            // Legacy normalized codes still rebuild their fixed messages; Child text is cleaned again.
            $item['message'] = $item['error']['message'] ?? ( 'completed' === $item['outcome'] && 'update_gate' === $item['trigger_source'] ? 'Pre-update backup downloaded; no snapshot recorded.' : $override_message );
        }
        return array(
            'items' => $items,
            'total' => $total,
        );
    }
}
