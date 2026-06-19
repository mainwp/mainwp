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
class MainWP_Backup_Restore_Transfer_Driver implements MainWP_Backup_Chunk_Transfer_Sizes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Lifetime of presigned pull URLs in seconds. Generated immediately
     * before the child task is created, so the window starts when the
     * child can first use them.
     *
     * @var int
     */
    const PRESIGN_TTL_SECONDS = 3600;

    /**
     * Whether a restore can skip staging and let the child pull its
     * artifacts straight from cloud storage.
     *
     * All four gates must hold: cloud pull was not already tried for
     * this job, the child advertised the capability, every artifact the
     * child needs is remote-sourced, and every involved destination can
     * presign GET URLs.
     *
     * @param object $website    Website object.
     * @param array  $plan       Plan entries.
     * @param array  $checkpoint Job checkpoint.
     *
     * @return bool
     */
    public function can_use_cloud_pull( object $website, array $plan, array $checkpoint ): bool {
        if ( ! empty( $checkpoint['cloud_pull_tried'] ) ) {
            return false;
        }
        if ( ! MainWP_Backup_Monitor::child_supports_cloud_pull( $website ) ) {
            return false;
        }

        $snapshot_ids = array();
        foreach ( $plan as $entry ) {
            if ( ! empty( $entry['adopt_only'] ) ) {
                continue;
            }
            if ( ! in_array( $entry['source'], array( 'remote', 'remote_chunked' ), true ) ) {
                return false;
            }
            $snapshot_ids[ (int) $entry['snapshot_id'] ] = true;
        }
        if ( empty( $snapshot_ids ) ) {
            return false;
        }

        foreach ( array_keys( $snapshot_ids ) as $snapshot_id ) {
            if ( null === $this->presignable_upload_for_snapshot( $snapshot_id ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the presigned pull manifest for a plan, filling each plan
     * entry's expected hash from the upload record on the way.
     *
     * Returns null whenever the manifest cannot be complete - an upload
     * recorded before artifact hashes existed, a missing chunk hash, or
     * a presigned URL that is not https - and the caller stages through
     * the standard path instead. No partial manifest ever leaves here.
     *
     * @param array $plan Plan entries (by reference; sha256 is filled in).
     *
     * @return array|null { artifacts: array } or null.
     */
    public function generate_pull_manifest( array &$plan ): ?array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- all-or-nothing manifest assembly.
        $uploads   = array();
        $artifacts = array();

        foreach ( $plan as $index => $entry ) {
            if ( ! empty( $entry['adopt_only'] ) ) {
                continue;
            }
            $snapshot_id = (int) $entry['snapshot_id'];

            if ( ! isset( $uploads[ $snapshot_id ] ) ) {
                $upload = $this->presignable_upload_for_snapshot( $snapshot_id );
                if ( null === $upload ) {
                    return null;
                }
                $uploads[ $snapshot_id ] = $upload;
            }

            $source = isset( $entry['source_file'] ) ? (string) $entry['source_file'] : '';
            $record = isset( $uploads[ $snapshot_id ]['artifacts'][ $source ] ) ? $uploads[ $snapshot_id ]['artifacts'][ $source ] : null;
            if ( null === $record || empty( $record['sha256'] ) ) {
                return null; // Uploaded before hashes were recorded: stage instead.
            }

            $prefix        = $this->snapshot_prefix( $snapshot_id );
            $provider      = $uploads[ $snapshot_id ]['provider'];
            $expected_host = $provider->object_host();
            $item          = array(
                'file'   => (string) $entry['file'],
                'sha256' => (string) $record['sha256'],
                'size'   => isset( $record['size'] ) ? (int) $record['size'] : 0,
            );

            if ( 'chunked' === ( isset( $record['mode'] ) ? $record['mode'] : '' ) && ! empty( $record['chunks'] ) ) {
                $chunks = array();
                foreach ( (array) $record['chunks'] as $chunk ) {
                    if ( empty( $chunk['hash'] ) ) {
                        return null;
                    }
                    $url = $provider->presigned_get_url( $prefix . 'chunks/' . $chunk['hash'] . '.bin', static::PRESIGN_TTL_SECONDS );
                    if ( 0 !== strpos( $url, 'https://' ) || wp_parse_url( $url, PHP_URL_HOST ) !== $expected_host ) {
                        return null;
                    }
                    $chunks[] = array(
                        'hash' => (string) $chunk['hash'],
                        'algo' => isset( $chunk['algo'] ) ? (string) $chunk['algo'] : 'sha256',
                        'url'  => $url,
                    );
                }
                $item['chunks'] = $chunks;
            } else {
                $url = $provider->presigned_get_url( $prefix . 'files/' . $source, static::PRESIGN_TTL_SECONDS );
                if ( 0 !== strpos( $url, 'https://' ) || wp_parse_url( $url, PHP_URL_HOST ) !== $expected_host ) {
                    return null;
                }
                $item['url'] = $url;
            }

            $plan[ $index ]['sha256'] = (string) $record['sha256'];
            if ( empty( $plan[ $index ]['size'] ) ) {
                $plan[ $index ]['size'] = (int) $record['size'];
            }
            $artifacts[] = $item;
        }

        if ( empty( $artifacts ) ) {
            return null;
        }

        return array( 'artifacts' => $artifacts );
    }

    /**
     * The first PRESIGNABLE completed upload of a snapshot: its
     * provider plus the upload record's artifacts indexed by source
     * basename (test seam).
     *
     * Scans every complete upload job, so a non-presignable destination
     * that happened to upload first never shadows a presignable copy at
     * another destination.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return array|null { provider: MainWP_Backup_Presignable_Provider,
     *                      artifacts: array<string,array> } or null.
     */
    protected function presignable_upload_for_snapshot( int $snapshot_id ): ?array {
        foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id ) as $job ) {
            if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE !== $job->status || ! is_array( $job->checkpoint ) || empty( $job->checkpoint['artifacts'] ) ) {
                continue;
            }

            $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
            if ( null === $destination ) {
                continue;
            }
            try {
                $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
            } catch ( \Throwable $e ) {
                continue;
            }
            if ( ! $provider instanceof MainWP_Backup_Presignable_Provider || ! $provider->supports_presigned_get() ) {
                continue;
            }

            $by_file = array();
            foreach ( (array) $job->checkpoint['artifacts'] as $artifact ) {
                if ( ! empty( $artifact['file'] ) ) {
                    $by_file[ (string) $artifact['file'] ] = $artifact;
                }
            }

            return array(
                'provider'  => $provider,
                'artifacts' => $by_file,
            );
        }
        return null;
    }

    /**
     * The remote key prefix of a snapshot (test seam).
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return string {site}/{snapshot}/.
     */
    protected function snapshot_prefix( int $snapshot_id ): string {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        $site_id  = null !== $snapshot ? (int) $snapshot->site_id : 0;
        return $site_id . '/' . $snapshot_id . '/';
    }

    /**
     * Whether every whole-file cloud artifact a restore plan would pull is
     * still present in offsite storage.
     *
     * The restore preflight calls this before any transfer or destructive
     * work. It HEAD-confirms each whole-file remote artifact and returns
     * false the moment one is gone, so the orchestrator can stop with a
     * clear reason. It never false-blocks: local and chunked artifacts, an
     * unresolvable provider, or a transient HEAD error all defer to staging,
     * which verifies bytes as it streams them.
     *
     * @param array $plan Restore plan entries.
     *
     * @return bool False only when a remote artifact is confirmed missing.
     */
    public function remote_artifacts_present( array $plan ): bool {
        foreach ( $plan as $entry ) {
            if ( ! empty( $entry['adopt_only'] ) || 'remote' !== ( isset( $entry['source'] ) ? (string) $entry['source'] : '' ) ) {
                continue;
            }

            $snapshot_id = (int) $entry['snapshot_id'];
            $upload      = $this->presignable_upload_for_snapshot( $snapshot_id );
            if ( null === $upload || ! method_exists( $upload['provider'], 'head_object' ) ) {
                continue;
            }

            $source_file = isset( $entry['source_file'] ) ? (string) $entry['source_file'] : '';
            if ( '' === $source_file ) {
                continue;
            }

            try {
                if ( false === $upload['provider']->head_object( $this->snapshot_prefix( $snapshot_id ) . 'files/' . $source_file ) ) {
                    return false;
                }
            } catch ( \Throwable $e ) {
                continue; // A transient HEAD error must not false-block a restore.
            }
        }

        return true;
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

            $outcome = $this->stage_one( $staging, $plan[ $index ], $deadline );
            if ( 'error' === $outcome['state'] ) {
                return array(
                    'done'  => false,
                    'error' => $outcome['error'],
                );
            }
            if ( 'pending' === $outcome['state'] ) {
                // Partial progress (a chunked reassembly mid-flight); the
                // caller persists the plan and the next tick continues.
                return array(
                    'done'  => false,
                    'error' => null,
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
     * @param string $staging  Staging dir.
     * @param array  $entry    Plan entry (by reference).
     * @param float  $deadline Slice deadline (epoch).
     *
     * @return array { state: staged|pending|error, error: string|null }
     */
    private function stage_one( string $staging, array &$entry, float $deadline ): array {
        if ( 'local' === $entry['source'] && file_exists( $entry['local_path'] ) ) {
            $entry['staged_path'] = $entry['local_path'];
            $entry['sha256']      = (string) hash_file( 'sha256', $entry['local_path'] );
            return $this->stage_state( 'staged' );
        }

        if ( 'remote' === $entry['source'] ) {
            return $this->stage_from_remote( $staging, $entry );
        }

        if ( 'remote_chunked' === $entry['source'] ) {
            return $this->stage_from_remote_chunked( $staging, $entry, $deadline );
        }

        return $this->stage_state(
            'error',
            sprintf(
                /* translators: %s: artifact file name. */
                __( 'A required backup file is not available from any source: %s', 'mainwp' ),
                $entry['file']
            )
        );
    }

    /**
     * Download a whole-file remote artifact into staging.
     *
     * @param string $staging Staging dir.
     * @param array  $entry   Plan entry (by reference).
     *
     * @return array Stage state.
     */
    protected function stage_from_remote( string $staging, array &$entry ): array {
        $provider = $this->provider_for_snapshot( (int) $entry['snapshot_id'] );
        if ( null === $provider ) {
            return $this->stage_state( 'error', __( 'No storage destination holds a copy of this backup.', 'mainwp' ) );
        }

        $temp  = $staging . $entry['file'] . '.downloading';
        $final = $staging . $entry['file'];

        if ( ! $provider->get_object( $entry['remote_key'], $temp ) ) {
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'Downloading the backup file from cloud storage failed: %s', 'mainwp' ),
                    $entry['file']
                )
            );
        }
        if ( ! rename( $temp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote of the staged download.
            return $this->stage_state( 'error', __( 'The staged download could not be moved into place.', 'mainwp' ) );
        }

        $downloaded = (string) hash_file( 'sha256', $final );

        // When a backup-time hash was recorded, a downloaded cloud object
        // that does not match it is corrupt or tampered: reject it rather
        // than restore unverified bytes. Uploads predating artifact hashing
        // carry no expected hash, so those still stage as before.
        $expected = isset( $entry['expected_sha256'] ) ? (string) $entry['expected_sha256'] : '';
        if ( '' !== $expected && ! hash_equals( $expected, $downloaded ) ) {
            wp_delete_file( $final );
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'The backup file downloaded from cloud storage failed its integrity check: %s', 'mainwp' ),
                    $entry['file']
                )
            );
        }

        $entry['staged_path'] = $final;
        $entry['sha256']      = $downloaded;
        return $this->stage_state( 'staged' );
    }

    /**
     * Reassemble a chunked remote artifact from its content-addressed
     * pieces.
     *
     * The snapshot's manifest.json names each artifact's ordered chunk
     * list; every chunk downloads, verifies against its content hash,
     * and appends to an assembly file whose cursor lives on the plan
     * entry - a slice that runs out of budget resumes at the next
     * chunk. The finished file must match the manifest's recorded size
     * before it is promoted and hashed.
     *
     * @param string $staging  Staging dir.
     * @param array  $entry    Plan entry (by reference).
     * @param float  $deadline Slice deadline (epoch).
     *
     * @return array Stage state.
     */
    protected function stage_from_remote_chunked( string $staging, array &$entry, float $deadline ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- resumable verify-then-append loop.
        $provider = $this->provider_for_snapshot( (int) $entry['snapshot_id'] );
        if ( null === $provider ) {
            return $this->stage_state( 'error', __( 'No storage destination holds a copy of this backup.', 'mainwp' ) );
        }

        if ( empty( $entry['chunks'] ) ) {
            $manifest = $this->fetch_manifest( $provider, $entry, $staging );
            if ( null === $manifest ) {
                return $this->stage_state(
                    'error',
                    sprintf(
                        /* translators: %s: artifact file name. */
                        __( 'The cloud copy of this backup has no readable manifest: %s', 'mainwp' ),
                        $entry['file']
                    )
                );
            }
            $entry['chunks']     = $manifest['chunks'];
            $entry['total_size'] = $manifest['size'];
        }

        $assembly = $staging . $entry['file'] . '.assembling';
        $cursor   = isset( $entry['reassembly'] ) ? (array) $entry['reassembly'] : array(
            'next'  => 0,
            'bytes' => 0,
        );

        // A stale assembly file that does not match the cursor restarts
        // clean - appending to unknown bytes would corrupt the artifact.
        $on_disk = file_exists( $assembly ) ? (int) filesize( $assembly ) : 0;
        if ( $on_disk !== (int) $cursor['bytes'] ) {
            wp_delete_file( $assembly );
            $cursor = array(
                'next'  => 0,
                'bytes' => 0,
            );
        }

        $prefix      = $entry['snapshot_id'] . '/';
        $chunk_count = count( $entry['chunks'] );

        while ( $cursor['next'] < $chunk_count ) {
            if ( microtime( true ) >= $deadline ) {
                $entry['reassembly'] = $cursor;
                return $this->stage_state( 'pending' );
            }

            $chunk = $entry['chunks'][ $cursor['next'] ];
            $key   = $entry['chunk_prefix'] . 'chunks/' . $chunk['hash'] . '.bin';
            $piece = $staging . $entry['file'] . '.chunk';

            if ( ! $provider->get_object( $key, $piece ) ) {
                $entry['reassembly'] = $cursor;
                return $this->stage_state(
                    'error',
                    sprintf(
                        /* translators: 1: artifact file name, 2: chunk hash. */
                        __( 'A piece of the cloud copy could not be downloaded: %1$s (chunk %2$s).', 'mainwp' ),
                        $entry['file'],
                        $chunk['hash']
                    )
                );
            }

            // Chunks are content-addressed with the algorithm the upload
            // recorded (xxh128 where available); verify with that one.
            $algo   = isset( $chunk['algo'] ) && is_string( $chunk['algo'] ) && in_array( $chunk['algo'], hash_algos(), true ) ? $chunk['algo'] : 'sha256';
            $actual = (string) hash_file( $algo, $piece );
            if ( ! hash_equals( (string) $chunk['hash'], $actual ) ) {
                wp_delete_file( $piece );
                return $this->stage_state(
                    'error',
                    sprintf(
                        /* translators: %s: artifact file name. */
                        __( 'A piece of the cloud copy failed its integrity check: %s. The stored backup may be corrupted.', 'mainwp' ),
                        $entry['file']
                    )
                );
            }

            $bytes = (string) file_get_contents( $piece ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- verified chunk bytes.
            wp_delete_file( $piece );
            $fh = fopen( $assembly, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append to the assembly file.
            if ( false === $fh || strlen( $bytes ) !== fwrite( $fh, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append.
                if ( false !== $fh ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                }
                return $this->stage_state( 'error', __( 'The reassembled backup could not be written. The dashboard may be out of disk space.', 'mainwp' ) );
            }
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.

            $cursor['bytes'] += strlen( $bytes );
            ++$cursor['next'];
            $entry['reassembly'] = $cursor;
        }

        if ( ! empty( $entry['total_size'] ) && (int) $cursor['bytes'] !== (int) $entry['total_size'] ) {
            wp_delete_file( $assembly );
            unset( $entry['reassembly'] );
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'The reassembled backup does not match its recorded size: %s. The stored backup may be incomplete.', 'mainwp' ),
                    $entry['file']
                )
            );
        }

        $final = $staging . $entry['file'];
        if ( ! rename( $assembly, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote of the reassembled artifact.
            return $this->stage_state( 'error', __( 'The reassembled backup could not be moved into place.', 'mainwp' ) );
        }

        $entry['staged_path'] = $final;
        $entry['sha256']      = (string) hash_file( 'sha256', $final );
        unset( $entry['reassembly'], $entry['chunks'] );
        return $this->stage_state( 'staged' );
    }

    /**
     * Fetch and parse the snapshot manifest for a chunked artifact.
     *
     * @param MainWP_Backup_Storage_Provider $provider Provider.
     * @param array                          $entry    Plan entry (by reference;
     *                                                 chunk_prefix is recorded).
     * @param string                         $staging  Staging dir.
     *
     * @return array|null { chunks: array, size: int } or null.
     */
    protected function fetch_manifest( MainWP_Backup_Storage_Provider $provider, array &$entry, string $staging ): ?array {
        // The manifest lives beside the artifact's chunks under the
        // snapshot prefix, which the remote key scheme encodes as
        // {site}/{snapshot}/.
        $snapshot              = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $entry['snapshot_id'] );
        $site_id               = null !== $snapshot ? (int) $snapshot->site_id : 0;
        $entry['chunk_prefix'] = $site_id . '/' . (int) $entry['snapshot_id'] . '/';

        $temp = $staging . 'manifest-' . (int) $entry['snapshot_id'] . '.json';
        if ( ! $provider->get_object( $entry['chunk_prefix'] . 'manifest.json', $temp ) ) {
            return null;
        }

        $decoded = json_decode( (string) file_get_contents( $temp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local read of the fetched manifest.
        wp_delete_file( $temp );
        if ( ! is_array( $decoded ) || empty( $decoded['artifacts'] ) ) {
            return null;
        }

        foreach ( (array) $decoded['artifacts'] as $artifact ) {
            if ( isset( $artifact['file'] ) && $artifact['file'] === $entry['file'] && ! empty( $artifact['chunks'] ) ) {
                return array(
                    'chunks' => array_values( (array) $artifact['chunks'] ),
                    'size'   => isset( $artifact['size'] ) ? (int) $artifact['size'] : 0,
                );
            }
        }
        return null;
    }

    /**
     * Resolve a provider for a snapshot's completed upload (test seam).
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return MainWP_Backup_Storage_Provider|null Provider or null.
     */
    protected function provider_for_snapshot( int $snapshot_id ): ?MainWP_Backup_Storage_Provider {
        $destination = $this->find_destination_for_snapshot( $snapshot_id );
        if ( null === $destination ) {
            return null;
        }
        try {
            return MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Build a stage-one state.
     *
     * @param string      $state staged|pending|error.
     * @param string|null $error Error text.
     *
     * @return array State.
     */
    private function stage_state( string $state, ?string $error = null ): array {
        return array(
            'state' => $state,
            'error' => $error,
        );
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

            if ( $outcome['bytes_received'] <= $cursor['offset'] ) {
                // An ok ack that confirmed no new bytes would loop forever.
                return array(
                    'done'      => false,
                    'error'     => esc_html__( 'The child site acknowledged the chunk without storing any of it.', 'mainwp' ),
                    'retryable' => false,
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
