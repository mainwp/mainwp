<?php
/**
 * Persistent cancellation of scheduled backup runs.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cancellation has its own lease so a busy backup lane cannot prevent a stop.
 */
class MainWP_Backup_Run_Cancel {

    /**
     * The list view must not query or mutate state while rendering a row.
     *
     * @param object $task Loaded task row.
     * @return array|null Request times for this open run.
     */
    public static function pending_request( object $task ): ?array {
        $request = self::request_for_run( $task );
        return null === $request ? null : array(
            'requested' => (int) $request['requested'],
            'deadline'  => (int) $request['deadline'],
        );
    }

    /**
     * Whether a stored request names this run, open or closed.
     *
     * @param object $task Fresh task row.
     * @param int    $run Run identity a driver is about to start a site for.
     * @return bool Whether starting work for that run is refused.
     */
    public static function blocks_run( object $task, int $run ): bool {
        $request = json_decode( (string) ( $task->run_cancel ?? '' ), true );
        return is_array( $request ) && $run > 0 && (int) ( $request['run'] ?? 0 ) === $run;
    }

    /**
     * Ignore requests left behind by a previous run.
     *
     * @param object $task Fresh task row.
     * @return array|null Valid request bound to the open run.
     */
    private static function request_for_run( object $task ): ?array {
        $request = json_decode( (string) ( $task->run_cancel ?? '' ), true );
        if ( ! is_array( $request ) || (int) ( $request['run'] ?? 0 ) !== (int) $task->last_run || (int) $task->completed >= (int) $task->last_run || empty( $request['requested'] ) || empty( $request['deadline'] ) ) {
            return null;
        }
        return $request;
    }

    /**
     * Paused tasks and disabled backups still need cancellation.
     *
     * @param string $caller Entry point for the pass.
     * @return void
     */
    public static function process_pending( string $caller ): void {
        if ( ! MainWP_Install::backup_task_run_cancel_ready() ) {
            return;
        }
        global $wpdb;
        $table = esc_sql( MainWP_DB::instance()->get_table_name( 'wp_backup' ) );
        $ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE run_cancel IS NOT NULL AND run_cancel <> '' AND completed < last_run" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fresh requests, including paused tasks and native-backups-off requests.
        foreach ( $ids as $id ) {
            self::process( (int) $id, $caller );
        }
    }

    /**
     * One pass survives restarts through the task row alone.
     *
     * @param int    $task_id Task identity.
     * @param string $caller Entry point for the pass.
     * @return array Status, unsettled count, and close outcome.
     */
    public static function process( int $task_id, string $caller ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- callers identify the entry point without changing lease semantics.
        $result = self::result( 'none' );
        if ( ! MainWP_Install::backup_task_run_cancel_ready() ) {
            return $result;
        }
        $db   = MainWP_DB_Backup::instance();
        $task = $db->get_backup_task_by_id( $task_id );
        if ( ! $task || empty( $task->run_cancel ) ) {
            return $result;
        }
        $request = self::request_for_run( $task );
        $run     = (int) $task->last_run;
        if ( null === $request ) {
            // A closed run keeps its own request so a driver that already passed
            // its admission check still refuses to start; the next claim clears it.
            if ( ! self::blocks_run( $task, $run ) ) {
                $db->compare_and_swap_run_cancel( $task_id, $run, $task->run_cancel, null );
            }
            return self::result( 'stale' );
        }
        $sites = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );
        $now   = time();
        if ( ! empty( $request['lease'] ) && (int) ( $request['lease_until'] ?? 0 ) >= $now ) {
            return self::result( 'busy', count( self::waiting_sites( $task, $sites, $request ) ) );
        }
        $token                  = wp_generate_password( 20, false );
        $request['lease']       = $token;
        $request['lease_until'] = $now + 120;
        $request['settled']     = is_array( $request['settled'] ?? null ) ? $request['settled'] : array();
        $raw                    = self::request_bytes( $request );
        if ( ! $db->compare_and_swap_run_cancel( $task_id, $run, $task->run_cancel, $raw ) ) {
            return self::result( 'busy', count( self::waiting_sites( $task, $sites, $request ) ) );
        }
        try {
            $sent = 0;
            foreach ( self::waiting_sites( $task, $sites, $request ) as $site_id => $progress ) {
                if ( time() >= (int) $request['deadline'] || $sent >= 3 ) {
                    break;
                }
                $child_id = (string) ( $progress->child_backup_task_id ?? '' );
                if ( '' === $child_id || MainWP_Backup_Executor::is_creating_marker( $child_id ) ) {
                    continue;
                }
                $website = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
                if ( ! $website ) {
                    continue;
                }
                if ( ! self::renew( $task, $request, $raw, $token ) ) {
                    return self::result( 'busy', count( self::waiting_sites( $task, $sites, $request ) ) );
                }
                $now     = time();
                $timeout = min( 30, (int) $request['lease_until'] - $now, (int) $request['deadline'] - $now );
                if ( $timeout < 1 ) {
                    break;
                }
                $bound = static function () use ( $timeout ) {
                    return $timeout;
                };
                add_filter( 'mainwp_fetch_url_site_timeout', $bound, PHP_INT_MAX );
                try {
                    ++$sent;
                    $reply = MainWP_Backup_Executor::send_backup_task_cancel( $website, $child_id );
                } catch ( \Throwable $e ) {
                    // The Child may have acted before the connection failed; retry its id.
                    $reply = null;
                } finally {
                    remove_filter( 'mainwp_fetch_url_site_timeout', $bound, PHP_INT_MAX );
                }
                if ( is_array( $reply ) && ! array_key_exists( 'error', $reply ) && in_array( $reply['result'] ?? null, array( 'ok', 'already_terminal', 'not_found' ), true ) ) {
                    if ( ! self::renew( $task, $request, $raw, $token ) ) {
                        return self::result( 'busy', count( self::waiting_sites( $task, $sites, $request ) ) );
                    }
                    $next                        = $request;
                    $next['settled'][ $site_id ] = $reply['result'];
                    $bytes                       = self::request_bytes( $next );
                    if ( ! $db->compare_and_swap_run_cancel( $task_id, $run, $raw, $bytes ) ) {
                        return self::result( 'busy', count( self::waiting_sites( $task, $sites, $request ) ) );
                    }
                    $request = $next;
                    $raw     = $bytes;
                }
            }
            $waiting = self::waiting_sites( $task, $sites, $request );
            $expired = time() >= (int) $request['deadline'];
            if ( $expired || ( empty( $waiting ) && ! $db->has_live_child_task_for_run( $task_id ) && ! $db->has_pending_record_for_run( $task_id ) ) ) {
                if ( ! self::renew( $task, $request, $raw, $token ) ) {
                    return self::result( 'busy', count( $waiting ) );
                }
                if ( $db->update_backup_completed( $task_id, $run ) ) {
                    MainWP_Backup_Run_History::finalize_run( $task_id, $run, 'cancelled' );
                    $db->update_backup_notices( $task_id, self::close_note( $expired, array_keys( $waiting ) ) . "\n" );
                    foreach ( $request['settled'] as $site_id => $answer ) {
                        $progress = $db->get_backup_task_progress( $task_id, (int) $site_id );
                        if ( $progress && (int) $progress->dtsFetched >= $run ) {
                            $db->update_backup_task_progress_if_marker(
                                $task_id,
                                (int) $site_id,
                                (string) $progress->child_backup_task_id,
                                array(
                                    'child_backup_task_id' => '',
                                    'child_phase'          => '',
                                )
                            );
                        }
                    }
                    do_action( 'mainwp_backup_diag_run_close', $task_id, $run, 'cancelled', count( $sites ) );
                    return self::result( 'closed', count( $waiting ), $expired ? 'not_confirmed' : 'cancelled' );
                }
                return self::result( 'stale' );
            }
            return self::result( 'pending', count( $waiting ) );
        } finally {
            $released                = $request;
            $released['lease']       = '';
            $released['lease_until'] = 0;
            if ( (int) $request['lease_until'] >= time() ) {
                $db->compare_and_swap_run_cancel( $task_id, $run, $raw, self::request_bytes( $released ) );
            }
        }
    }

    /**
     * Renewal must use the holder's bytes; re-reading and rebasing would adopt a successor.
     *
     * @param object $task Task identity.
     * @param array  $request Held request.
     * @param string $raw Held bytes, updated on success.
     * @param string $token This pass's token.
     * @return bool Whether this pass still owns a live request.
     */
    private static function renew( object $task, array &$request, string &$raw, string $token ): bool {
        $now = time();
        if ( $request['lease'] !== $token || (int) $request['lease_until'] < $now ) {
            return false;
        }
        $current = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $task->id );
        if ( ! $current || (int) $current->last_run !== (int) $task->last_run || null === self::pending_request( $current ) ) {
            return false;
        }
        $next                = $request;
        $next['lease_until'] = $now + 120;
        $bytes               = self::request_bytes( $next );
        if ( ! MainWP_DB_Backup::instance()->compare_and_swap_run_cancel( (int) $task->id, (int) $task->last_run, $raw, $bytes ) ) {
            return false;
        }
        $request = $next;
        $raw     = $bytes;
        return true;
    }

    /**
     * Creating markers stay unsettled even after their ordinary create TTL.
     *
     * @param object $task Run identity.
     * @param array  $sites Run's site ids.
     * @param array  $request Held request.
     * @return array Site ids mapped to fresh progress rows.
     */
    private static function waiting_sites( object $task, array $sites, array $request ): array {
        $waiting = array();
        foreach ( $sites as $site_id ) {
            $progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, (int) $site_id );
            if ( isset( $request['settled'][ $site_id ] ) || ! $progress || (int) $progress->dtsFetched < (int) $task->last_run || '' === (string) $progress->child_backup_task_id || in_array( $progress->child_phase, array( 'completed', 'failed' ), true ) ) {
                continue;
            }
            $waiting[ $site_id ] = $progress;
        }
        return $waiting;
    }

    /**
     * Keep empty settlement maps as JSON objects across renewals.
     *
     * @param array $request Cancellation state.
     * @return string Stored bytes.
     */
    private static function request_bytes( array $request ): string {
        $request['settled'] = (object) ( $request['settled'] ?? array() );
        return (string) wp_json_encode( $request );
    }

    /**
     * Keep the close wording in one place.
     *
     * @param bool  $expired Whether the deadline was reached.
     * @param array $waiting Unsettled site ids.
     * @return string Escaped task note.
     */
    private static function close_note( bool $expired, array $waiting ): string {
        if ( ! $expired ) {
            return esc_html__( 'Cancelled.', 'mainwp' );
        }
        $names = array();
        foreach ( $waiting as $site_id ) {
            $site    = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
            $names[] = esc_html( $site ? $site->name : (string) $site_id );
        }
        return sprintf(
            /* translators: %s: Comma-separated site names. */
            esc_html__( 'Cancelled. Not confirmed for: %s.', 'mainwp' ),
            implode( ', ', $names )
        );
    }

    /**
     * Keep pass results consistent across entry points.
     *
     * @param string $status Pass state.
     * @param int    $waiting Unsettled count.
     * @param string $closed_as Close outcome.
     * @return array Pass result.
     */
    private static function result( string $status, int $waiting = 0, string $closed_as = '' ): array {
        return array(
            'status'    => $status,
            'waiting'   => $waiting,
            'closed_as' => $closed_as,
        );
    }
}
