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
        }
        if ( ! in_array( $trigger, array( 'schedule', 'manual', 'ability', 'update_gate', 'restore_safety' ), true ) ) {
            return 0;
        }
        return (int) self::write(
            static function () use ( $task_id, $site_id, $started, $trigger, $user_id, $restore_job_id, $ref ) {
                global $wpdb;
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
     * The first terminal observation wins, even if a later poll disagrees.
     *
     * @param int      $id History identity.
     * @param string   $outcome Terminal outcome.
     * @param string   $error_code Code only, never a stored message.
     * @param int|null $snapshot_id Snapshot belonging to this run.
     * @return bool Whether a running row changed.
     */
    public static function finalize( int $id, string $outcome, string $error_code = '', ?int $snapshot_id = null ): bool {
        if ( ! in_array( $outcome, array( 'completed', 'failed', 'cancelled', 'reset', 'incomplete' ), true ) ) {
            return false;
        }
        return (bool) self::write(
            static function () use ( $id, $outcome, $error_code, $snapshot_id ) {
                global $wpdb;
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT trigger_source FROM ' . self::table_name() . ' WHERE id = %d AND outcome = %s', $id, 'running' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- fixed table; values prepared.
                if ( '' !== $wpdb->last_error ) {
                    return false;
                }
                if ( ! $row ) {
                    return 0;
                }
                $safe    = MainWP_Backup_Operations::safe_error( $error_code );
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
     * Restrict a late finalizer to the run it observed.
     *
     * @param int      $task_id Task identity.
     * @param int      $started Claimed run timestamp.
     * @param string   $outcome Terminal outcome.
     * @param int|null $site_id Null closes remaining sites in the run.
     * @param string   $error_code Safe formatter input.
     * @param int|null $snapshot_id This site's snapshot.
     */
    public static function finalize_run( int $task_id, int $started, string $outcome, ?int $site_id = null, string $error_code = '', ?int $snapshot_id = null ): void {
        global $wpdb;
        $sql    = 'SELECT id FROM ' . self::table_name() . ' WHERE task_id = %d AND started = %d AND outcome = %s';
        $values = array( $task_id, $started, 'running' );
        if ( null !== $site_id ) {
            $sql     .= ' AND site_id = %d';
            $values[] = $site_id;
        }
        self::finalize_matching( $wpdb->prepare( $sql, $values ), $outcome, $error_code, $snapshot_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed clauses; every value prepared.
    }

    /**
     * Ad-hoc lanes have a Child handle instead of a Dashboard task run.
     *
     * @param int    $site_id Site identity.
     * @param string $ref Child task identity.
     * @param string $trigger Lane identity.
     * @param string $outcome Terminal outcome.
     * @param string $error_code Safe formatter input.
     */
    public static function finalize_ref( int $site_id, string $ref, string $trigger, string $outcome, string $error_code = '' ): void {
        global $wpdb;
        self::finalize_matching( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE site_id = %d AND ref = %s AND trigger_source = %s AND outcome = %s', $site_id, $ref, $trigger, 'running' ), $outcome, $error_code ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table; every value prepared.
    }

    /**
     * Read failures need the same containment as failed writes.
     *
     * @param string   $sql Prepared identity query.
     * @param string   $outcome Terminal outcome.
     * @param string   $error_code Safe formatter input.
     * @param int|null $snapshot_id This site's snapshot.
     */
    private static function finalize_matching( string $sql, string $outcome, string $error_code, ?int $snapshot_id = null ): void {
        self::write(
            static function () use ( $sql, $outcome, $error_code, $snapshot_id ) {
                global $wpdb;
                $ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- callers prepare the fixed identity query.
                if ( '' !== $wpdb->last_error ) {
                    return false;
                }
                foreach ( $ids as $id ) {
                    self::finalize( (int) $id, $outcome, $error_code, $snapshot_id );
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
        $table    = self::table_name();
        $total    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders and replacements are built together from fixed filters.
        $per_page = min( 100, max( 1, $per_page ) );
        $values[] = $per_page;
        $values[] = ( max( 1, $page ) - 1 ) * $per_page;
        $items    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY started DESC, id DESC LIMIT %d OFFSET %d', $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders and replacements are built together from fixed filters.
        foreach ( $items as &$item ) {
            $item['error'] = '' !== $item['error_code'] ? MainWP_Backup_Operations::safe_error( $item['error_code'] ) : null;
            // Old rows may predate the safe-message contract; reads rebuild it too.
            $item['message'] = $item['error']['message'] ?? ( 'completed' === $item['outcome'] && 'update_gate' === $item['trigger_source'] ? 'Pre-update backup downloaded; no snapshot recorded.' : '' );
        }
        return array(
            'items' => $items,
            'total' => $total,
        );
    }
}
