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

    /**
     * Plan an artifact: read it in chunks, hash each chunk, and stage the
     * chunk bytes under their hash for upload. Idempotent across
     * re-entries; staged chunks with the expected size are reused, torn
     * ones are rewritten.
     *
     * @param string   $path       Artifact file path.
     * @param string   $staging    Staging directory for chunk files.
     * @param int|null $chunk_size Chunk size override; defaults to chunk_size().
     *
     * @return array|false Ordered list of {hash, algo, offset, length}, or false on failure.
     */
    public static function plan_artifact( $path, $staging, $chunk_size = null ) {
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
                if ( ! static::stage_chunk( $staging, $hash, $bytes ) ) {
                    return false;
                }

                $chunks[] = array(
                    'hash'   => $hash,
                    'algo'   => $algo,
                    'offset' => $offset,
                    'length' => $length,
                );

                $offset += $length;
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen above.
        }

        return $chunks;
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

        clearstatcache( true, $final );
        if ( is_file( $final ) && filesize( $final ) === strlen( $bytes ) ) {
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
