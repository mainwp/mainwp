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
     * Update job columns by id, stamping updated.
     *
     * @param int   $job_id Job id.
     * @param array $row    Columns to set.
     * @param array $format Column formats.
     *
     * @return void
     */
    protected function update_job( int $job_id, array $row, array $format ): void {
        $row['updated'] = time();
        $format[]       = '%d';

        $this->wpdb->update(
            $this->table(),
            $row,
            array( 'id' => $job_id ),
            $format,
            array( '%d' )
        );
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
