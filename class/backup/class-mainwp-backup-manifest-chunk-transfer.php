<?php
/**
 * MainWP Backup Manifest Chunk Transfer - pushes an oversized parent
 * manifest to a child site in pieces.
 *
 * A parent manifest over the inline cap cannot ride on the task-create
 * request, and force-running a full backup instead costs the user a
 * whole archive. This sender streams the local manifest file to the
 * child's staging receiver in hash-verified chunks - duplicate offsets
 * are acknowledged, a gap realigns from the receiver's confirmed
 * bytes, and a transport failure halves the chunk size down to a floor
 * before giving up. The completed session's token then references the
 * staged manifest on the create request.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Manifest_Chunk_Transfer
 */
class MainWP_Backup_Manifest_Chunk_Transfer { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Starting chunk size, raw bytes (~4 MiB of base64 on the wire).
     */
    const CHUNK_BYTES = 3145728;

    /**
     * Smallest chunk size worth retrying with.
     */
    const CHUNK_MIN_BYTES = 262144;

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Manifest_Chunk_Transfer|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Manifest_Chunk_Transfer
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Push a manifest file to the child in chunks.
     *
     * @param object $website       Website object.
     * @param string $manifest_path Absolute path to the local manifest file.
     * @param string $total_sha256  SHA-256 of the whole manifest.
     * @param int    $chunk_bytes   Starting chunk size (tests override).
     *
     * @return array { ok: bool, mtoken: string, error: string|null, error_code: string }
     */
    public function push( object $website, string $manifest_path, string $total_sha256, int $chunk_bytes = self::CHUNK_BYTES ): array {
        $size = (int) filesize( $manifest_path );
        if ( $size < 1 ) {
            return $this->failure( esc_html__( 'The file list from the previous backup is empty or unreadable.', 'mainwp' ) );
        }

        $offset = 0;
        $mtoken = '';

        while ( $offset < $size ) {
            $read = min( $chunk_bytes, $size - $offset );
            $raw  = (string) file_get_contents( $manifest_path, false, null, $offset, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local manifest read.
            if ( '' === $raw ) {
                return $this->failure( esc_html__( 'The file list from the previous backup could not be read from disk.', 'mainwp' ) );
            }
            $last = ( $offset + strlen( $raw ) >= $size );

            $outcome = $this->send_chunk( $website, $mtoken, $offset, $raw, $total_sha256, $last );

            if ( 'shrink' === $outcome['result'] ) {
                if ( $chunk_bytes <= self::CHUNK_MIN_BYTES ) {
                    return $this->failure( $outcome['error'] );
                }
                $chunk_bytes = max( self::CHUNK_MIN_BYTES, (int) ( $chunk_bytes / 2 ) );
                continue;
            }
            if ( 'realign' === $outcome['result'] ) {
                $offset = $outcome['bytes_received'];
                continue;
            }
            if ( 'error' === $outcome['result'] ) {
                return $this->failure( $outcome['error'] );
            }

            if ( '' !== $outcome['mtoken'] ) {
                $mtoken = $outcome['mtoken'];
            }
            if ( $outcome['bytes_received'] <= $offset ) {
                // An ok answer that confirmed no new bytes would loop forever.
                return $this->failure( esc_html__( 'The child site acknowledged the file-list transfer without storing any of it.', 'mainwp' ) );
            }
            $offset = $outcome['bytes_received'];
        }

        return array(
            'ok'         => true,
            'mtoken'     => $mtoken,
            'error'      => null,
            'error_code' => '',
        );
    }

    /**
     * Send one chunk and classify the child's answer.
     *
     * @param object $website      Website object.
     * @param string $mtoken       Staging token ('' opens the session).
     * @param int    $offset       Byte offset.
     * @param string $raw          Chunk bytes.
     * @param string $total_sha256 Whole-manifest sha256.
     * @param bool   $last         Whether this chunk completes the manifest.
     *
     * @return array { result: ok|shrink|realign|error, bytes_received: int, mtoken: string, error: string|null }
     */
    protected function send_chunk( object $website, string $mtoken, int $offset, string $raw, string $total_sha256, bool $last ): array {
        try {
            $response = MainWP_Connect::fetch_url_authed(
                $website,
                'backup_manifest_chunk',
                array(
                    'mtoken'       => $mtoken,
                    'offset'       => $offset,
                    'chunk_b64'    => base64_encode( $raw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire transport encoding for manifest bytes, not code.
                    'chunk_sha256' => hash( 'sha256', $raw ),
                    'total_sha256' => $total_sha256,
                    'final'        => $last ? 1 : 0,
                ),
                false,
                false,
                false
            );
        } catch ( \Throwable $e ) {
            // A transport failure on a large POST is the shrink signal: the
            // site may sit behind a proxy with a small request ceiling.
            return array(
                'result'         => 'shrink',
                'bytes_received' => $offset,
                'mtoken'         => '',
                'error'          => $e->getMessage(),
            );
        }

        if ( is_array( $response ) && isset( $response['error_code'] ) && 'mainwp_restore_chunk_gap' === $response['error_code'] ) {
            return array(
                'result'         => 'realign',
                'bytes_received' => isset( $response['bytes_received'] ) ? (int) $response['bytes_received'] : 0,
                'mtoken'         => '',
                'error'          => null,
            );
        }

        if ( ! is_array( $response ) || isset( $response['error'] ) || ! isset( $response['bytes_received'] ) ) {
            $message = is_array( $response ) && isset( $response['error'] )
                ? (string) $response['error']
                : esc_html__( 'The child site returned an unreadable answer to the file-list transfer.', 'mainwp' );
            return array(
                'result'         => 'error',
                'bytes_received' => $offset,
                'mtoken'         => '',
                'error'          => $message,
            );
        }

        return array(
            'result'         => 'ok',
            'bytes_received' => (int) $response['bytes_received'],
            'mtoken'         => isset( $response['mtoken'] ) ? (string) $response['mtoken'] : '',
            'error'          => null,
        );
    }

    /**
     * Build a send-failed outcome.
     *
     * @param string|null $detail Transport or child error detail.
     *
     * @return array Failure outcome.
     */
    private function failure( ?string $detail ): array {
        return array(
            'ok'         => false,
            'mtoken'     => '',
            'error'      => null !== $detail && '' !== $detail ? $detail : MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED ),
            'error_code' => MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED,
        );
    }
}
