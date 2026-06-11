<?php
/**
 * MainWP Backup Restore Transfer Driver - staging and dashboard-to-child transfer.
 *
 * Staging makes every plan artifact a verified local file: artifacts
 * still on the dashboard's disk are hashed in place; artifacts that only
 * exist in cloud storage download through the provider's get_object()
 * into a per-job staging directory. The sha256 computed here is the
 * contract the child verifies against, per chunk and per artifact.
 *
 * Transfer pushes the staged bytes to the child as base64 chunks over
 * the existing authenticated callable channel. Chunks append at the
 * offset the child confirms; a gap response realigns the cursor; an
 * oversized-request failure shrinks the chunk size adaptively (down to
 * a floor) instead of counting as an attempt - a body-size cap is a
 * capacity signal, not a fault.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Restore_Transfer_Driver
 */
class MainWP_Backup_Restore_Transfer_Driver { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default raw bytes per chunk (~4 MB base64 on the wire).
     */
    const CHUNK_BYTES = 3145728;

    /**
     * Smallest chunk the adaptive shrink will try.
     */
    const CHUNK_MIN_BYTES = 262144;

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Restore_Transfer_Driver|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Restore_Transfer_Driver
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * The staging directory for a restore job.
     *
     * @param int $job_id Restore job id.
     *
     * @return string Directory with trailing slash.
     */
    public function staging_dir( int $job_id ): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'mainwp/restore-staging/' . (int) $job_id . '/';
    }

    /**
     * Stage every plan artifact: verify local files, fetch remote ones,
     * and record each artifact's sha256.
     *
     * @param int   $job_id   Restore job id.
     * @param array $plan     Plan entries (by reference; staged_path and
     *                        sha256 are filled in).
     * @param float $deadline Slice deadline (epoch).
     *
     * @return array { done: bool, error: string|null }
     */
    public function stage_artifacts( int $job_id, array &$plan, float $deadline ): array {
        $staging = $this->staging_dir( $job_id );
        if ( ! is_dir( $staging ) ) {
            wp_mkdir_p( $staging );
        }

        foreach ( $plan as $index => $entry ) {
            if ( ! empty( $entry['staged_path'] ) && file_exists( $entry['staged_path'] ) && ! empty( $entry['sha256'] ) ) {
                continue;
            }
            if ( microtime( true ) >= $deadline && $index > 0 ) {
                return array(
                    'done'  => false,
                    'error' => null,
                );
            }

            $outcome = $this->stage_one( $staging, $plan[ $index ] );
            if ( null !== $outcome ) {
                return array(
                    'done'  => false,
                    'error' => $outcome,
                );
            }
        }

        return array(
            'done'  => true,
            'error' => null,
        );
    }

    /**
     * Stage a single artifact.
     *
     * @param string $staging Staging dir.
     * @param array  $entry   Plan entry (by reference).
     *
     * @return string|null Error text, or null when staged.
     */
    private function stage_one( string $staging, array &$entry ): ?string {
        if ( 'local' === $entry['source'] && file_exists( $entry['local_path'] ) ) {
            $entry['staged_path'] = $entry['local_path'];
            $entry['sha256']      = (string) hash_file( 'sha256', $entry['local_path'] );
            return null;
        }

        if ( 'remote' === $entry['source'] ) {
            return $this->stage_from_remote( $staging, $entry );
        }

        if ( 'remote_chunked' === $entry['source'] ) {
            // Chunked artifacts reassemble from their content-addressed
            // pieces; that staging path lands as its own step.
            return sprintf(
                /* translators: %s: artifact file name. */
                __( 'This backup is stored in cloud storage in chunked form and cannot be staged yet: %s', 'mainwp' ),
                $entry['file']
            );
        }

        return sprintf(
            /* translators: %s: artifact file name. */
            __( 'A required backup file is not available from any source: %s', 'mainwp' ),
            $entry['file']
        );
    }

    /**
     * Download a whole-file remote artifact into staging.
     *
     * @param string $staging Staging dir.
     * @param array  $entry   Plan entry (by reference).
     *
     * @return string|null Error text, or null when staged.
     */
    private function stage_from_remote( string $staging, array &$entry ): ?string {
        $destination = $this->find_destination_for_snapshot( (int) $entry['snapshot_id'] );
        if ( null === $destination ) {
            return __( 'No storage destination holds a copy of this backup.', 'mainwp' );
        }

        try {
            $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
        } catch ( \Throwable $e ) {
            return $e->getMessage();
        }

        $temp  = $staging . $entry['file'] . '.downloading';
        $final = $staging . $entry['file'];

        if ( ! $provider->get_object( $entry['remote_key'], $temp ) ) {
            return sprintf(
                /* translators: %s: artifact file name. */
                __( 'Downloading the backup file from cloud storage failed: %s', 'mainwp' ),
                $entry['file']
            );
        }
        if ( ! rename( $temp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote of the staged download.
            return __( 'The staged download could not be moved into place.', 'mainwp' );
        }

        $entry['staged_path'] = $final;
        $entry['sha256']      = (string) hash_file( 'sha256', $final );
        return null;
    }

    /**
     * Push staged artifacts to the child as verified chunks.
     *
     * @param object $website  Website object.
     * @param string $task_id  Child restore task id.
     * @param array  $plan     Staged plan entries.
     * @param array  $cursor   Transfer cursor (by reference):
     *                         { artifact_index, offset, chunk_bytes }.
     * @param float  $deadline Slice deadline (epoch).
     *
     * @return array { done: bool, error: string|null, retryable: bool }
     */
    public function transfer_artifacts( object $website, string $task_id, array $plan, array &$cursor, float $deadline ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- chunk loop with realign and adaptive sizing.
        $cursor = array_merge(
            array(
                'artifact_index' => 0,
                'offset'         => 0,
                'chunk_bytes'    => self::CHUNK_BYTES,
            ),
            $cursor
        );

        $count = count( $plan );
        while ( $cursor['artifact_index'] < $count ) {
            $entry = $plan[ $cursor['artifact_index'] ];

            if ( ! empty( $entry['adopt_only'] ) ) {
                ++$cursor['artifact_index'];
                $cursor['offset'] = 0;
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                return array(
                    'done'      => false,
                    'error'     => null,
                    'retryable' => false,
                );
            }

            $size = (int) filesize( $entry['staged_path'] );
            $raw  = (string) file_get_contents( $entry['staged_path'], false, null, $cursor['offset'], max( 1, min( $cursor['chunk_bytes'], $size - $cursor['offset'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded read of the staged artifact.
            $last = $cursor['offset'] + strlen( $raw ) >= $size;

            $outcome = $this->send_chunk( $website, $task_id, $entry, $cursor['offset'], $raw, $last );

            if ( 'shrink' === $outcome['result'] ) {
                if ( $cursor['chunk_bytes'] <= self::CHUNK_MIN_BYTES ) {
                    return array(
                        'done'      => false,
                        'error'     => $outcome['error'],
                        'retryable' => true,
                    );
                }
                $cursor['chunk_bytes'] = max( self::CHUNK_MIN_BYTES, (int) ( $cursor['chunk_bytes'] / 2 ) );
                continue;
            }
            if ( 'realign' === $outcome['result'] ) {
                $cursor['offset'] = $outcome['bytes_received'];
                continue;
            }
            if ( 'error' === $outcome['result'] ) {
                return array(
                    'done'      => false,
                    'error'     => $outcome['error'],
                    'retryable' => $outcome['retryable'],
                );
            }

            $cursor['offset'] = $outcome['bytes_received'];
            if ( $last ) {
                ++$cursor['artifact_index'];
                $cursor['offset'] = 0;
            }
        }

        return array(
            'done'      => true,
            'error'     => null,
            'retryable' => false,
        );
    }

    /**
     * Send one chunk and classify the response.
     *
     * @param object $website Website object.
     * @param string $task_id Child restore task id.
     * @param array  $entry   Plan entry.
     * @param int    $offset  Chunk offset.
     * @param string $raw     Chunk bytes.
     * @param bool   $last    Whether this is the artifact's final chunk.
     *
     * @return array { result: ok|realign|shrink|error, bytes_received: int,
     *                 error: string|null, retryable: bool }
     */
    protected function send_chunk( object $website, string $task_id, array $entry, int $offset, string $raw, bool $last ): array {
        try {
            $response = MainWP_Connect::fetch_url_authed(
                $website,
                'restore_artifact_chunk',
                array(
                    'task_id'      => $task_id,
                    'file'         => $entry['file'],
                    'offset'       => $offset,
                    'chunk_b64'    => base64_encode( $raw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire encoding of binary artifact bytes.
                    'chunk_sha256' => hash( 'sha256', $raw ),
                    'final'        => $last ? 1 : 0,
                ),
                false,
                false,
                false
            );
        } catch ( \Throwable $e ) {
            // Transport failures on large bodies are how hosts with small
            // request caps present; shrink before counting it a fault.
            return array(
                'result'         => 'shrink',
                'bytes_received' => $offset,
                'error'          => $e->getMessage(),
                'retryable'      => true,
            );
        }

        if ( is_array( $response ) && isset( $response['error_code'] ) && 'mainwp_restore_chunk_gap' === $response['error_code'] ) {
            return array(
                'result'         => 'realign',
                'bytes_received' => isset( $response['bytes_received'] ) ? (int) $response['bytes_received'] : 0,
                'error'          => null,
                'retryable'      => true,
            );
        }
        if ( ! is_array( $response ) || isset( $response['error'] ) ) {
            $message = is_array( $response ) && isset( $response['error'] ) ? (string) $response['error'] : __( 'The child site returned an unreadable transfer response.', 'mainwp' );
            return array(
                'result'         => 'error',
                'bytes_received' => $offset,
                'error'          => $message,
                'retryable'      => true,
            );
        }

        return array(
            'result'         => 'ok',
            'bytes_received' => isset( $response['bytes_received'] ) ? (int) $response['bytes_received'] : $offset + strlen( $raw ),
            'error'          => null,
            'retryable'      => false,
        );
    }

    /**
     * Remove a job's staging directory.
     *
     * @param int $job_id Restore job id.
     */
    public function cleanup_staging( int $job_id ): void {
        $dir = $this->staging_dir( $job_id );
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( (array) glob( $dir . '*' ) as $file ) {
            if ( is_file( $file ) ) {
                wp_delete_file( $file );
            }
        }
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- best-effort staging cleanup.
    }

    /**
     * The destination holding a snapshot's completed upload.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return object|null Destination row or null.
     */
    protected function find_destination_for_snapshot( int $snapshot_id ): ?object {
        foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id ) as $job ) {
            if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE === $job->status ) {
                $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
                if ( null !== $destination ) {
                    return $destination;
                }
            }
        }
        return null;
    }
}
