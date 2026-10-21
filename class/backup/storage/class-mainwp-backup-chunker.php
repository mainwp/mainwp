<?php
/**
 * MainWP Backup Chunker.
 *
 * Splits backup artifacts into fixed-size, content-addressed chunks for
 * resumable uploads. Each chunk's bytes are staged under its hash via a
 * tmp-then-atomic-rename write, so a final chunk filename only ever
 * exists with complete bytes. The filename is still only the expected
 * hash, not proof of content, so re-entry verifies the staged size and
 * rewrites on mismatch instead of trusting the name.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || die();

/**
 * Class MainWP_Backup_Chunker
 */
class MainWP_Backup_Chunker { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default chunk size in bytes (4 MiB).
     *
     * @var int
     */
    const CHUNK_SIZE = 4194304;

    /**
     * Files below this size upload whole instead of chunked (512 KiB).
     *
     * @var int
     */
    const SMALL_FILE_THRESHOLD = 524288;

    /**
     * Matches the download preflight's private margin so staging preserves the same headroom.
     *
     * @var int
     */
    private const STAGING_DISK_MARGIN = 100 * MB_IN_BYTES;

    /**
     * The default chunk size in bytes.
     *
     * @return int
     */
    public static function chunk_size() {
        return static::CHUNK_SIZE;
    }

    /**
     * The whole-file upload threshold in bytes.
     *
     * @return int
     */
    public static function small_file_threshold() {
        return static::SMALL_FILE_THRESHOLD;
    }

    /**
     * Whether a file is large enough to go through the chunk pipeline.
     *
     * @param string $path File path.
     *
     * @return bool
     */
    public static function should_chunk( $path ) {
        $size = is_file( $path ) ? filesize( $path ) : false;
        return false !== $size && $size >= static::SMALL_FILE_THRESHOLD;
    }

    /**
     * The chunk hash algorithm: xxh128 where available, sha256 otherwise.
     *
     * @return string
     */
    public static function hash_algo() {
        return in_array( 'xxh128', hash_algos(), true ) ? 'xxh128' : 'sha256';
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- The registry factory returns MainWP_Exception.
    /**
     * Plan an artifact: read it in chunks, hash each chunk, and stage the
     * chunk bytes under their hash for upload. Idempotent across
     * re-entries; staged chunks with the expected size are reused, torn
     * ones are rewritten.
     *
     * @param string        $path       Artifact file path.
     * @param string        $staging    Staging directory for chunk files.
     * @param int|null      $chunk_size Chunk size override; defaults to chunk_size().
     * @param callable|null $keepalive  Called between the hash and stage passes; false stops the plan.
     *
     * @return array|false Ordered list of {hash, algo, offset, length}, or false on failure.
     * @throws MainWP_Exception When staging would consume the reserved disk headroom.
     */
    public static function plan_artifact( $path, $staging, $chunk_size = null, $keepalive = null ) {
        $chunk_size = empty( $chunk_size ) ? static::chunk_size() : (int) $chunk_size;

        if ( ! is_file( $path ) || $chunk_size < 1 ) {
            return false;
        }

        if ( ! is_dir( $staging ) && ! mkdir( $staging, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- staging dir outside WP uploads helpers.
            return false;
        }

        $handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed read of large artifacts.
        if ( false === $handle ) {
            return false;
        }

        $algo   = static::hash_algo();
        $chunks = array();
        $offset = 0;
        $needed = array();

        try {
            while ( ! feof( $handle ) ) {
                $bytes = fread( $handle, $chunk_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streamed read of large artifacts.
                if ( false === $bytes ) {
                    return false;
                }
                $length = strlen( $bytes );
                if ( 0 === $length ) {
                    break;
                }

                $hash = hash( $algo, $bytes );
                if ( ! static::staged_chunk_matches( $staging, $hash, $length ) ) {
                    $needed[ $hash ] = $length;
                }

                $chunks[] = array(
                    'hash'   => $hash,
                    'algo'   => $algo,
                    'offset' => $offset,
                    'length' => $length,
                );

                $offset += $length;
            }

            // The artifact is read twice, so the caller's lease is renewed before the second pass.
            if ( null !== $keepalive && ! call_user_func( $keepalive ) ) {
                return false;
            }

            // Hash first: a crashed pass may have staged bytes without saving its plan.
            $free = static::free_space_bytes( $staging );
            if ( null !== $free && array_sum( $needed ) + self::STAGING_DISK_MARGIN > $free ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DISK_FULL );
            }

            foreach ( $chunks as $chunk ) {
                if ( static::staged_chunk_matches( $staging, $chunk['hash'], $chunk['length'] ) ) {
                    continue;
                }
                if ( 0 !== fseek( $handle, $chunk['offset'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- source slices share the open artifact handle.
                    return false;
                }
                $bytes = fread( $handle, $chunk['length'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- bounded source slice for staging.
                if ( false === $bytes || strlen( $bytes ) !== $chunk['length'] || hash( $algo, $bytes ) !== $chunk['hash'] || ! static::stage_chunk( $staging, $chunk['hash'], $bytes ) ) {
                    return false;
                }
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen above.
        }

        return $chunks;
    }
    // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

    /**
     * A torn write still needs a complete temporary copy before replacement.
     *
     * @param string $staging Staging directory.
     * @param string $hash    Chunk hash.
     * @param int    $length  Expected bytes.
     *
     * @return bool
     */
    private static function staged_chunk_matches( $staging, $hash, $length ): bool {
        $final = $staging . DIRECTORY_SEPARATOR . $hash . '.bin';
        clearstatcache( true, $final );
        return is_file( $final ) && filesize( $final ) === $length;
    }

    /**
     * Hosts that disable disk-space reporting can still stage uploads.
     *
     * @param string $staging Directory on the staging filesystem.
     *
     * @return int|null Free bytes, or null when unknown.
     */
    protected static function free_space_bytes( string $staging ): ?int {
        $free = function_exists( 'disk_free_space' ) ? @disk_free_space( $staging ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- advisory check; some hosts disable disk-space reporting.

        /**
         * Filters available bytes on the upload staging filesystem.
         *
         * @param float|false $free    Free bytes, or false when unknown.
         * @param string      $staging Staging directory.
         */
        $free = apply_filters( 'mainwp_backup_upload_staging_free_space', $free, $staging );
        return is_numeric( $free ) && is_finite( (float) $free ) && $free >= 0 ? (int) $free : null;
    }

    /**
     * Stage one chunk's bytes under its hash, atomically. An existing
     * staged file is reused only when its size matches; a torn leftover
     * from a crashed pass is rewritten.
     *
     * @param string $staging Staging directory.
     * @param string $hash    Chunk content hash.
     * @param string $bytes   Chunk bytes.
     *
     * @return bool
     */
    private static function stage_chunk( $staging, $hash, $bytes ) {
        $final = $staging . DIRECTORY_SEPARATOR . $hash . '.bin';

        if ( static::staged_chunk_matches( $staging, $hash, strlen( $bytes ) ) ) {
            return true;
        }

        $tmp = $final . '.tmp.' . getmypid() . '.' . wp_rand( 1000, 9999 );

        if ( strlen( $bytes ) !== file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- staging write outside WP uploads helpers.
            if ( is_file( $tmp ) ) {
                unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- staging cleanup.
            }
            return false;
        }

        if ( ! rename( $tmp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic publish of the staged chunk.
            unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- staging cleanup.
            return false;
        }

        return true;
    }
}
