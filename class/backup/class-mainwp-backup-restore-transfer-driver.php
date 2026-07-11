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
     * Consecutive floor-size transport failures tolerated per chunk
     * before the transfer surfaces a retryable error. Below this, a
     * dropped connection at the minimum chunk size resends the same
     * chunk instead of burning a job attempt and its backoff.
     *
     * @var int
     */
    const FLOOR_FAILS_MAX = 3;

    /**
     * Pause before an in-slice floor retry, seconds.
     *
     * @var int
     */
    const FLOOR_RETRY_SLEEP_SECONDS = 5;

    /**
     * Consecutive ok chunks before a shrunk chunk size grows back
     * toward the default, so one bad minute early in a transfer costs
     * minutes instead of dragging the whole run at the floor rate.
     * This cadence also drives the ceiling machinery below: with a
     * known-bad size on record the streak bisects toward it instead of
     * doubling, and converged streaks count toward a heal reprobe.
     *
     * @var int
     */
    const GROW_BACK_STREAK = 32;

    /**
     * Stop bisecting toward a known-bad ceiling once the remaining gap
     * is this small. Probing forever for a few extra KB per chunk costs
     * more in failed sends than the bandwidth it could ever earn.
     *
     * @var int
     */
    const CEILING_CONVERGE_STEP_BYTES = 16384;

    /**
     * Converged clean streaks before re-probing a SOFT ceiling - one
     * set by a timeout/reset that may have been transient load, so it
     * heals quickly instead of pinning the transfer small forever.
     *
     * @var int
     */
    const CEILING_REPROBE_STREAKS_SOFT = 2;

    /**
     * Converged clean streaks before re-probing a HARD ceiling - one
     * set by a deterministic host rejection (NOMAINWP body cap,
     * Imunify360 refusal). A proxy's request-size limit rarely changes
     * mid-run, so these wait far longer between probes.
     *
     * @var int
     */
    const CEILING_REPROBE_STREAKS_HARD = 8;

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
            // An encrypted snapshot must stage and decrypt on the dashboard:
            // the child has no key, so it cannot pull ciphertext straight from
            // cloud storage.
            if ( $this->snapshot_is_encrypted( $snapshot_id ) ) {
                return false;
            }
            if ( null === $this->presignable_upload_for_snapshot( $snapshot_id ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a snapshot's offsite copies are encrypted.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return bool
     */
    private function snapshot_is_encrypted( int $snapshot_id ): bool {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        $meta     = ( null !== $snapshot && isset( $snapshot->encryption ) ) ? $snapshot->encryption : array();
        return MainWP_Backup_Encryption::meta_is_encrypted( $meta );
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
                    'done'       => false,
                    'error'      => $outcome['error'],
                    'error_code' => isset( $outcome['error_code'] ) ? $outcome['error_code'] : null,
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

        $enc = $this->resolve_artifact_encryption( $entry );
        if ( null === $enc ) {
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'This backup is encrypted but its key is unavailable on this dashboard: %s', 'mainwp' ),
                    $entry['file']
                )
            );
        }

        $temp  = $staging . $entry['file'] . '.downloading';
        $final = $staging . $entry['file'];

        $free = $this->free_space_bytes( dirname( $temp ) );
        if ( null !== $free && $free < (int) $entry['size'] ) {
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'Not enough free disk space on the dashboard to download this backup file: %s', 'mainwp' ),
                    $entry['file']
                ),
                MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL
            );
        }

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

        // Decrypt in place before hashing so the integrity check runs against
        // the plaintext, exactly as it does for an unencrypted artifact. The
        // AAD must be the basename the upload sealed under - the snapshot's own
        // archive name (source_file), not the chain-namespaced staging name in
        // 'file' - or AES-GCM authentication fails and a valid backup reads as
        // undecryptable.
        $sealed_name = ! empty( $entry['source_file'] ) ? (string) $entry['source_file'] : (string) $entry['file'];
        $decrypted   = $this->maybe_decrypt_file( $final, $enc, 'snapshot:' . (int) $entry['snapshot_id'] . ':whole:' . $sealed_name );
        if ( ! $decrypted['ok'] ) {
            wp_delete_file( $final );
            $hedged = MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT === $decrypted['error_code']
                ? __( 'The stored backup is truncated or corrupted; this is not a key problem.', 'mainwp' )
                : __( 'The stored backup may be corrupted, or the encryption key may be wrong.', 'mainwp' );
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: 1: artifact file name, 2: corruption or hedged key/corruption reason. */
                    __( 'The backup file could not be decrypted: %1$s. %2$s', 'mainwp' ),
                    $entry['file'],
                    $hedged
                ),
                $decrypted['error_code']
            );
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

        // Resolve the artifact's data key once for the whole reassembly. A
        // chain can mix an encrypted artifact with a legacy plaintext one, so
        // this is resolved per artifact from its own snapshot.
        $enc = $this->resolve_artifact_encryption( $entry );
        if ( null === $enc ) {
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'This backup is encrypted but its key is unavailable on this dashboard: %s', 'mainwp' ),
                    $entry['file']
                )
            );
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

        // Checked only at a fresh start, not on every resumed slice: a
        // reassembly already holding bytes on disk is proof the space for
        // those bytes exists, and re-checking against the shrinking
        // remaining need on every tick would be redundant.
        if ( 0 === $on_disk ) {
            $remaining = max( 0, (int) $entry['size'] - $on_disk );
            $free      = $this->free_space_bytes( dirname( $assembly ) );
            if ( null !== $free && $free < $remaining ) {
                return $this->stage_state(
                    'error',
                    sprintf(
                        /* translators: %s: artifact file name. */
                        __( 'Not enough free disk space on the dashboard to download this backup file: %s', 'mainwp' ),
                        $entry['file']
                    ),
                    MainWP_Backup_Error_Codes::ERR_RESTORE_DISK_FULL
                );
            }
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

            // Decrypt the piece before verifying and appending, so the content
            // hash and byte count are taken over the plaintext - the chunk hash
            // recorded at backup time is the plaintext hash.
            $decrypted = $this->maybe_decrypt_file( $piece, $enc, 'snapshot:' . (int) $entry['snapshot_id'] . ':chunk:' . $chunk['hash'] );
            if ( ! $decrypted['ok'] ) {
                wp_delete_file( $piece );
                $hedged = MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT === $decrypted['error_code']
                    ? __( 'The stored backup is truncated or corrupted; this is not a key problem.', 'mainwp' )
                    : __( 'The stored backup may be corrupted, or the encryption key may be wrong.', 'mainwp' );
                return $this->stage_state(
                    'error',
                    sprintf(
                        /* translators: 1: artifact file name, 2: chunk hash, 3: corruption or hedged key/corruption reason. */
                        __( 'A piece of the cloud copy could not be decrypted: %1$s (chunk %2$s). %3$s', 'mainwp' ),
                        $entry['file'],
                        $chunk['hash'],
                        $hedged
                    ),
                    $decrypted['error_code']
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

        $entry['sha256'] = (string) hash_file( 'sha256', $final );

        // A reassembled artifact that does not match its backup-time whole-file
        // hash is corrupt or tampered; reject it rather than restore unverified
        // bytes. Uploads predating artifact hashing carry no expected hash.
        $expected = isset( $entry['expected_sha256'] ) ? (string) $entry['expected_sha256'] : '';
        if ( '' !== $expected && ! hash_equals( $expected, $entry['sha256'] ) ) {
            wp_delete_file( $final );
            unset( $entry['reassembly'], $entry['chunks'] );
            return $this->stage_state(
                'error',
                sprintf(
                    /* translators: %s: artifact file name. */
                    __( 'The reassembled backup failed its integrity check: %s. The stored backup may be corrupted.', 'mainwp' ),
                    $entry['file']
                )
            );
        }

        $entry['staged_path'] = $final;
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

        // The manifest records the snapshot's own archive names, but the
        // plan's 'file' is the chain-namespaced staging name (s{id}-...).
        // Match on the raw source_file, as the whole-file path above does.
        $plan_name = ! empty( $entry['source_file'] ) ? (string) $entry['source_file'] : (string) $entry['file'];
        foreach ( (array) $decoded['artifacts'] as $artifact ) {
            if ( isset( $artifact['file'] ) && $artifact['file'] === $plan_name && ! empty( $artifact['chunks'] ) ) {
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
     * Free disk space at a path, or null when the host disables the check
     * (test seam).
     *
     * Best-effort: a host without disk_free_space() available never blocks
     * a restore over it.
     *
     * @param string $dir Directory to check.
     *
     * @return int|null Free bytes, or null when unknown.
     */
    protected function free_space_bytes( string $dir ): ?int {
        if ( ! function_exists( 'disk_free_space' ) ) {
            return null;
        }
        $free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- advisory best-effort check.
        return false !== $free ? (int) $free : null;
    }

    /**
     * Build a stage-one state.
     *
     * @param string      $state      staged|pending|error.
     * @param string|null $error      Error text.
     * @param string|null $error_code Stable error code, when the caller
     *                                needs to classify the failure.
     *
     * @return array State.
     */
    private function stage_state( string $state, ?string $error = null, ?string $error_code = null ): array {
        return array(
            'state'      => $state,
            'error'      => $error,
            'error_code' => $error_code,
        );
    }

    /**
     * Resolve an artifact's decryption context from its own snapshot, so a
     * chain mixing encrypted and legacy plaintext snapshots decrypts each
     * artifact correctly. Returns a context that decrypts nothing for a
     * plaintext snapshot, or null when the snapshot is encrypted but its key
     * cannot be loaded (the caller surfaces that as a staging error).
     *
     * @param array $entry Plan entry.
     *
     * @return array|null { encrypt: bool, dek?: string } or null when the key is missing.
     */
    private function resolve_artifact_encryption( array $entry ): ?array {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $entry['snapshot_id'] );
        $meta     = ( null !== $snapshot && isset( $snapshot->encryption ) ) ? $snapshot->encryption : array();
        if ( ! MainWP_Backup_Encryption::meta_is_encrypted( $meta ) ) {
            return array( 'encrypt' => false );
        }

        $encryption = MainWP_Backup_Encryption::instance();
        $kek        = $encryption->load_kek();
        if ( null === $kek ) {
            return null;
        }
        try {
            $dek = $encryption->dek_from_meta( $meta, (int) $entry['snapshot_id'], $kek );
        } catch ( \Throwable $e ) {
            return null;
        }
        return array(
            'encrypt' => true,
            'dek'     => $dek,
        );
    }

    /**
     * Decrypt a downloaded object file in place when the context is encrypting;
     * a pass-through for a plaintext context. Distinguishes a truncated/malformed
     * blob (never a key symptom) from a GCM authentication failure (a wrong key,
     * a wrong AAD, and tampering are indistinguishable here, so that code stays
     * hedged) - the caller turns either into a staging error rather than
     * restoring garbage.
     *
     * @param string $path Local file holding the downloaded bytes.
     * @param array  $enc  Decryption context.
     * @param string $aad  The AAD the unit was sealed with.
     *
     * @return array { ok: bool, error_code: string|null }
     */
    private function maybe_decrypt_file( string $path, array $enc, string $aad ): array {
        if ( empty( $enc['encrypt'] ) ) {
            return array(
                'ok'         => true,
                'error_code' => null,
            );
        }
        $cipher = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local staged object read.
        if ( false === $cipher ) {
            return array(
                'ok'         => false,
                'error_code' => MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT,
            );
        }
        try {
            $plain = MainWP_Backup_Archive_Cipher::open( $cipher, $enc['dek'], $aad );
        } catch ( MainWP_Backup_Cipher_Malformed_Exception $e ) {
            return array(
                'ok'         => false,
                'error_code' => MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT,
            );
        } catch ( MainWP_Backup_Cipher_Auth_Exception $e ) {
            return array(
                'ok'         => false,
                'error_code' => MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_KEY,
            );
        }
        if ( strlen( $plain ) !== file_put_contents( $path, $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local staged plaintext write.
            return array(
                'ok'         => false,
                'error_code' => MainWP_Backup_Error_Codes::ERR_RESTORE_DECRYPT_CORRUPT,
            );
        }
        return array(
            'ok'         => true,
            'error_code' => null,
        );
    }

    /**
     * Push staged artifacts to the child as verified chunks.
     *
     * @param object $website  Website object.
     * @param string $task_id  Child restore task id.
     * @param array  $plan     Staged plan entries.
     * @param array  $cursor   Transfer cursor (by reference):
     *                         { artifact_index, offset, chunk_bytes,
     *                         floor_fails, ok_streak, ceiling_bytes,
     *                         ceiling_soft, ceiling_streak_count,
     *                         ceiling_cleared_bytes, good_bytes }.
     * @param float  $deadline Slice deadline (epoch).
     *
     * @return array { done: bool, error: string|null, retryable: bool }
     */
    public function transfer_artifacts( object $website, string $task_id, array $plan, array &$cursor, float $deadline ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- chunk loop with realign, adaptive sizing, and floor retries.
        $cursor = array_merge(
            array(
                'artifact_index'        => 0,
                'offset'                => 0,
                'chunk_bytes'           => $this->default_chunk_bytes(),
                'floor_fails'           => 0,
                'ok_streak'             => 0,
                // A checkpoint written before the ceiling fields existed
                // backfills here to "no ceiling learned": exactly today's
                // doubling behavior until the first failure teaches one.
                'ceiling_bytes'         => 0,
                'ceiling_soft'          => false,
                'ceiling_streak_count'  => 0,
                'ceiling_cleared_bytes' => 0,
                'good_bytes'            => 0,
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

            $diag_start = microtime( true );
            $outcome    = $this->send_chunk( $website, $task_id, $entry, $cursor['offset'], $raw, $last );

            /**
             * Fires after each restore transfer chunk send, so the
             * diagnostics recorder can chart transfer throughput and the
             * otherwise silent adaptive chunk-size shrinking.
             *
             * @param string      $task_id   Child restore task id.
             * @param string      $file      Artifact file name.
             * @param array       $cursor    Transfer cursor before the send.
             * @param string      $result    ok|realign|shrink|error.
             * @param int         $chunk_len Bytes sent in this chunk.
             * @param float       $duration  Send duration in seconds.
             * @param string|null $error     Transport/child error string, null on ok.
             */
            do_action( 'mainwp_backup_diag_restore_transfer', (string) $task_id, (string) $entry['file'], $cursor, (string) $outcome['result'], strlen( $raw ), microtime( true ) - $diag_start, $outcome['error'] );

            if ( 'shrink' === $outcome['result'] ) {
                $cursor['ok_streak'] = 0;
                if ( $cursor['chunk_bytes'] <= self::CHUNK_MIN_BYTES ) {
                    ++$cursor['floor_fails'];
                    if ( $cursor['floor_fails'] >= self::FLOOR_FAILS_MAX ) {
                        $cursor['floor_fails'] = 0;
                        return array(
                            'done'      => false,
                            'error'     => $outcome['error'],
                            'retryable' => true,
                        );
                    }
                    // Ride through a one-off drop at the floor: pause and
                    // resend the same chunk while the slice budget allows,
                    // otherwise end the slice benignly - the streak is on
                    // the cursor, so the next slice keeps counting and a
                    // dead transport still escalates.
                    if ( microtime( true ) + self::FLOOR_RETRY_SLEEP_SECONDS < $deadline ) {
                        $this->floor_retry_sleep( self::FLOOR_RETRY_SLEEP_SECONDS );
                        continue;
                    }
                    return array(
                        'done'      => false,
                        'error'     => null,
                        'retryable' => false,
                    );
                }
                // Non-floor failure: remember the size that failed so a
                // later clean streak bisects toward it instead of blindly
                // re-probing the exact same known-bad size forever (the
                // production pattern: a ~1MB proxy body cap rejected every
                // 768KB grow-back probe, 35 times in one transfer). Floor
                // blips never reach here, so they cannot poison the ceiling
                // down to the floor value. A failure above an already
                // tighter ceiling teaches nothing new and keeps it.
                $failed_bytes = (int) $cursor['chunk_bytes'];
                if ( 0 === $cursor['ceiling_bytes'] || $failed_bytes <= $cursor['ceiling_bytes'] ) {
                    $cursor['ceiling_bytes']        = $failed_bytes;
                    $cursor['ceiling_soft']         = ! $this->is_deterministic_rejection( (string) $outcome['error'] );
                    $cursor['ceiling_streak_count'] = 0;
                }
                // Recover to the last size that actually worked - halving a
                // failed probe would erase the convergence progress every
                // cycle. The halve survives for a fresh cursor with nothing
                // proven yet, and for the equal-size case where the good
                // size itself started failing (a revert there would resend
                // the same failing size forever).
                if ( $cursor['good_bytes'] > 0 && $cursor['good_bytes'] < $failed_bytes ) {
                    $cursor['chunk_bytes'] = (int) $cursor['good_bytes'];
                } elseif ( $failed_bytes === (int) $cursor['ceiling_cleared_bytes'] && $failed_bytes - self::CEILING_CONVERGE_STEP_BYTES >= self::CHUNK_MIN_BYTES ) {
                    // The boundary flapped: this exact size just cleared its
                    // ceiling on a successful probe, so the cap sits right
                    // here and a halve would spend a full bisection re-climb
                    // rediscovering it. Step one converge step below instead.
                    // The memory is consumed and good_bytes zeroed, so a
                    // failure at the stepped-down size halves as before - the
                    // flap earns exactly one probe, never a revert loop.
                    $cursor['chunk_bytes']           = $failed_bytes - self::CEILING_CONVERGE_STEP_BYTES;
                    $cursor['good_bytes']            = 0;
                    $cursor['ceiling_cleared_bytes'] = 0;
                } else {
                    $cursor['chunk_bytes'] = max( self::CHUNK_MIN_BYTES, (int) ( $failed_bytes / 2 ) );
                }
                continue;
            }
            if ( 'realign' === $outcome['result'] ) {
                $cursor['ok_streak'] = 0;
                $cursor['offset']    = $outcome['bytes_received'];
                continue;
            }
            if ( 'error' === $outcome['result'] ) {
                $cursor['ok_streak'] = 0;
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
            $cursor['offset']      = $outcome['bytes_received'];
            $cursor['floor_fails'] = 0;
            $cursor['good_bytes']  = (int) $cursor['chunk_bytes'];
            if ( $cursor['ceiling_bytes'] > 0 && $cursor['chunk_bytes'] >= $cursor['ceiling_bytes'] ) {
                // A send at (or past) the recorded ceiling just worked -
                // the heal reprobe landed, or a mid-run filter raised the
                // size over it. Either way the cap is gone: forget it and
                // let plain doubling own growth again. Remember the size
                // that cleared, though: a flappy boundary fails this same
                // size moments later, and that failure should step down
                // one converge step instead of halving into a re-climb.
                $cursor['ceiling_bytes']         = 0;
                $cursor['ceiling_soft']          = false;
                $cursor['ceiling_streak_count']  = 0;
                $cursor['ceiling_cleared_bytes'] = (int) $cursor['chunk_bytes'];
            }
            ++$cursor['ok_streak'];
            if ( $cursor['ok_streak'] >= self::GROW_BACK_STREAK ) {
                $cursor['ok_streak'] = 0;
                $default             = $this->default_chunk_bytes();
                if ( $cursor['chunk_bytes'] < $default ) {
                    if ( 0 === $cursor['ceiling_bytes'] ) {
                        // A clean streak earns the size back: double toward
                        // the default so an early bad minute stops taxing
                        // the rest of the transfer. A failure at the new
                        // size just shrinks again - one probe send per
                        // streak, bounded.
                        $cursor['chunk_bytes'] = min( $default, $cursor['chunk_bytes'] * 2 );
                    } else {
                        $gap = $cursor['ceiling_bytes'] - $cursor['chunk_bytes'];
                        if ( $gap > self::CEILING_CONVERGE_STEP_BYTES ) {
                            // Bisect toward the known-bad size: each probe
                            // either raises the proven floor or tightens
                            // the ceiling, so this terminates in a handful
                            // of streaks at the host's true cap.
                            $cursor['chunk_bytes'] = min( $default, $cursor['chunk_bytes'] + intdiv( $gap, 2 ) );
                        } else {
                            // Converged. Hold the size and only rarely
                            // spend one send re-testing the ceiling itself,
                            // in case the cap was transient - soft ceilings
                            // (timeouts) re-test sooner than hard ones
                            // (deterministic host rejections).
                            ++$cursor['ceiling_streak_count'];
                            $reprobe_after = $cursor['ceiling_soft'] ? self::CEILING_REPROBE_STREAKS_SOFT : self::CEILING_REPROBE_STREAKS_HARD;
                            if ( $cursor['ceiling_streak_count'] >= $reprobe_after ) {
                                $cursor['ceiling_streak_count'] = 0;
                                $cursor['chunk_bytes']          = min( $default, $cursor['ceiling_bytes'] );
                            }
                        }
                    }
                }
            }
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
     * Pause before an in-slice floor retry (test seam).
     *
     * @param int $seconds Sleep duration.
     */
    protected function floor_retry_sleep( int $seconds ): void {
        sleep( $seconds );
    }

    /**
     * Starting chunk size for a fresh transfer cursor.
     *
     * The default is CHUNK_BYTES; the `mainwp_backup_restore_chunk_size`
     * filter lets a site tune the dashboard-to-child chunk for its own
     * request-body headroom, clamped to [CHUNK_MIN_BYTES, CHUNK_MAX_BYTES]
     * so a stray value can neither stall the loop nor exhaust memory. A
     * resumed or adaptively shrunk cursor keeps its own chunk_bytes; only
     * a fresh cursor takes this default.
     *
     * Cloud pull, the fast path for large restores, is unavailable for
     * encrypted artifacts because they decrypt on the dashboard first, so
     * those backups travel the chunk transfer and this filter is how a
     * site speeds them up.
     *
     * @return int Clamped starting chunk size in bytes.
     */
    private function default_chunk_bytes(): int {
        $bytes = (int) apply_filters( 'mainwp_backup_restore_chunk_size', self::CHUNK_BYTES );
        return max( self::CHUNK_MIN_BYTES, min( self::CHUNK_MAX_BYTES, $bytes ) );
    }

    /**
     * Whether a shrink error string names a deterministic host-level
     * rejection rather than a possibly transient transport fault.
     *
     * NOMAINWP is the connect layer's word for "the site answered with a
     * non-plugin body" - for an oversized chunk that is the proxy/WAF
     * rejection page served before WordPress boots. The Imunify360
     * refusal is the same class with its own message. Everything else
     * (HTTPERROR timeouts/resets, unknown throwables) stays "soft" on
     * purpose: an unknown failure should heal quickly, not pin the
     * transfer small for the rest of the run.
     *
     * @param string $error Shrink outcome error detail.
     *
     * @return bool True when the rejection is deterministic.
     */
    private function is_deterministic_rejection( string $error ): bool {
        return 0 === strpos( $error, 'NOMAINWP' ) || false !== strpos( $error, 'Imunify360' );
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
            // request caps present; shrink before counting it a fault. The
            // connect layer's real detail hides behind a token message
            // (HTTPERROR + extra); carry both so diagnostics can tell a
            // timeout from a reset from a proxy rejection.
            $detail = $e instanceof MainWP_Exception && ! empty( $e->get_message_extra() )
                ? $e->getMessage() . ' - ' . $e->get_message_extra()
                : $e->getMessage();
            return array(
                'result'         => 'shrink',
                'bytes_received' => $offset,
                'error'          => $detail,
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
