<?php
/**
 * MainWP Backup Job Store Base.
 *
 * Shared persistence plumbing for the backup job stores (upload and
 * restore): each store keeps one row per job with a status column, a
 * locked_until/locked_by lease so concurrent cron lanes never run the
 * same job, and a checkpoint JSON blob carrying resumable cursors. The
 * row-level guarantees live here: terminal transitions only land while
 * the row is still in an active status (first terminal writer wins),
 * and locks are only released by the token that holds them.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Job_Store_Base
 *
 * @package MainWP/Dashboard
 */
abstract class MainWP_Backup_Job_Store_Base extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- dedicated job tables; identifiers cannot be prepared.

    /**
     * The store's table name (full, prefixed).
     *
     * @return string Table name.
     */
    abstract protected function table(): string;

    /**
     * The statuses an in-flight job can hold. Terminal writes are only
     * allowed from these, so the first terminal writer wins and a job
     * that already reached a terminal status is never relabeled.
     *
     * @return string[] Active statuses.
     */
    abstract public static function active_statuses(): array;

    /**
     * Release a job's lock, only for the token that holds it.
     *
     * @param int    $job_id Job id.
     * @param string $token  The holder's lock token.
     *
     * @return void
     */
    public function release_lock( int $job_id, string $token ): void {
        $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table() . ' SET locked_until = 0, locked_by = %s, updated = %d WHERE id = %d AND locked_by = %s',
                '',
                time(),
                $job_id,
                $token
            )
        );
    }

    /**
     * Renew a still-live lease for its holder, extending it from now.
     *
     * The renewal doubles as the lease-loss probe: a worker whose lease was
     * reclaimed gets false and must stop its slice instead of writing
     * over the new holder.
     *
     * An ALREADY-EXPIRED lease cannot be renewed even when the token still
     * matches (the `locked_until >= now` predicate): once the lease lapsed
     * the worker has lost it, and letting it renew would let a slow holder
     * rescue a job the reclaim-ceiling sweep is about to permanently fail
     * between that sweep's SELECT and its terminal write.
     *
     * @param int      $job_id        Job id.
     * @param string   $token         The holder's lock token.
     * @param int      $lease_seconds New lease duration from now.
     * @param int|null $now           Clock override for tests.
     *
     * @return bool True when the token still holds a still-live lease.
     */
    public function renew_lease( int $job_id, string $token, int $lease_seconds, ?int $now = null ): bool {
        $now = null === $now ? time() : $now;

        $renewed = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table() . ' SET locked_until = %d, updated = %d WHERE id = %d AND locked_by = %s AND locked_until >= %d',
                $now + $lease_seconds,
                $now,
                $job_id,
                $token,
                $now
            )
        );
        if ( 1 === $renewed ) {
            return true;
        }
        if ( false === $renewed ) {
            // A query error is not a successful renewal - the lease was not
            // extended, so the caller must treat it as lost, not held.
            return false;
        }

        // Zero affected rows is ambiguous: a same-second renewal writes
        // identical values, which MySQL reports as unaffected. Only a still
        // live lease the token still holds counts as renewed - an expired or
        // reclaimed lease is lost.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT locked_by, locked_until FROM ' . $this->table() . ' WHERE id = %d', $job_id )
        );
        return null !== $row && $row->locked_by === $token && (int) $row->locked_until >= $now;
    }

    /**
     * Update job columns by id, stamping updated.
     *
     * When a lock token is given the write is fenced: it only lands while
     * that token still holds the row's lease, so a worker whose lease was
     * reclaimed can no longer overwrite the new holder's progress.
     *
     * @param int         $job_id     Job id.
     * @param array       $row        Columns to set.
     * @param array       $format     Column formats.
     * @param string|null $lock_token Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed (or, fenced, when the token
     *              still holds the lease); false when the lease was lost.
     */
    protected function update_job( int $job_id, array $row, array $format, ?string $lock_token = null ): bool {
        $row['updated'] = time();
        $format[]       = '%d';

        $where        = array( 'id' => $job_id );
        $where_format = array( '%d' );
        if ( null !== $lock_token ) {
            $where['locked_by'] = $lock_token;
            $where_format[]     = '%s';
        }

        $updated = $this->wpdb->update(
            $this->table(),
            $row,
            $where,
            $format,
            $where_format
        );

        if ( null === $lock_token ) {
            return false !== $updated;
        }
        if ( 1 === $updated ) {
            return true;
        }
        if ( false === $updated ) {
            // A query error (deadlock, lost connection) is not a lost lease
            // and must never read as a persisted write - the caller has to
            // know its checkpoint/status did not land.
            return false;
        }

        // Zero affected rows is ambiguous under MySQL: the fence refused the
        // write, or the values were already identical (a same-second
        // idempotent checkpoint). Only a confirmed token mismatch is a lost
        // lease.
        $holder = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SELECT locked_by FROM ' . $this->table() . ' WHERE id = %d', $job_id )
        );
        return $holder === $lock_token;
    }

    /**
     * Guarded write for terminal transitions: the row is only touched
     * while its status is still active, so the first terminal writer
     * wins and a job that already reached a terminal status is never
     * relabeled by a racing lane (mirrors release_lock's owner guard).
     *
     * Column names come from the stores' own terminal methods, never
     * from input.
     *
     * When a lock token is given the transition is additionally fenced on
     * the lease: a worker whose lease was reclaimed cannot terminally
     * relabel a job another worker now owns. Tokenless callers (the UI
     * cancel path, the reclaim-ceiling sweep) keep the plain
     * first-terminal-writer-wins behavior.
     *
     * @param int         $job_id     Job id.
     * @param array       $row        Columns.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when exactly one active row was updated.
     */
    protected function terminal_update( int $job_id, array $row, ?string $lock_token = null ): bool {
        $row['updated'] = time();

        $set    = array();
        $values = array();
        foreach ( $row as $column => $value ) {
            if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $column ) ) {
                return false;
            }
            $set[]    = '`' . $column . '` = ' . ( is_int( $value ) ? '%d' : '%s' );
            $values[] = $value;
        }

        $statuses     = static::active_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $fence = '';
        $binds = array_merge( $values, array( $job_id ), $statuses );
        if ( null !== $lock_token ) {
            $fence   = ' AND locked_by = %s';
            $binds[] = $lock_token;
        }

        $changed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted arrays.
                // nosemgrep: AIK_tainted-sql-string -- Column names are validated and backtick-quoted, and values use placeholders.
                'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $set ) . " WHERE id = %d AND status IN ( {$placeholders} ){$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SET columns are store-internal literals; placeholder list built from a counted array.
                $binds
            )
        );

        return 1 === $changed;
    }

    /**
     * Decode the checkpoint JSON on a job row.
     *
     * @param object|null $row Raw job row.
     *
     * @return object|null Row with checkpoint as array.
     */
    protected function decode_row( ?object $row ): ?object {
        if ( null === $row ) {
            return null;
        }

        $decoded         = json_decode( (string) $row->checkpoint, true );
        $row->checkpoint = is_array( $decoded ) ? $decoded : array();

        return $row;
    }
}
