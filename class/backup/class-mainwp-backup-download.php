<?php
/**
 * One-use downloads of a snapshot's local archive.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Snapshot downloads require current access at issue and redemption. */
class MainWP_Backup_Download {

    /**
     * Token records contain no absolute paths.
     *
     * @var string
     */
    private const TRANSIENT_PREFIX = 'mainwp_backup_snapshot_download_';

    /**
     * Keep download refusals together for the row and endpoint.
     *
     * @param string $kind Refusal kind.
     * @return \WP_Error
     */
    private static function refusal( string $kind ): \WP_Error {
        $messages = array(
            'link'        => __( 'This download link is not valid or has expired. Click Download again.', 'mainwp' ),
            'permission'  => __( 'You do not have permission to download this backup.', 'mainwp' ),
            'unavailable' => __( 'This backup archive is not available on this Dashboard.', 'mainwp' ),
            'read'        => __( 'The backup archive could not be read. Try downloading it again.', 'mainwp' ),
        );
        return new \WP_Error( 'mainwp_backup_download_' . $kind, $messages[ $kind ], array( 'status' => 403 ) );
    }

    /**
     * Resolve only the archive recorded for a complete standalone snapshot.
     *
     * @param object $snapshot Authorized snapshot row.
     * @return string|\WP_Error Canonical local path.
     */
    private static function local_archive( object $snapshot ): string|\WP_Error {
        if ( 'complete' !== $snapshot->status || ! in_array( $snapshot->type, array( 'full', 'db', 'files' ), true ) ) {
            return self::refusal( 'unavailable' );
        }
        $roles = 'db' === $snapshot->type ? array( 'db' ) : array( 'full', 'files' );
        foreach ( $snapshot->archives as $archive ) {
            if ( ! is_array( $archive ) || ! in_array( $archive['role'] ?? '', $roles, true ) ) {
                continue;
            }
            $path = $archive['path'] ?? '';
            $file = $archive['file'] ?? '';
            if ( ! is_string( $path ) || ! is_string( $file ) || '' === $file || basename( $path ) !== $file
                || false !== strpos( $file, '\\' ) || false !== strpos( $file, "\0" )
                || ! MainWP_Backup_Private_Root::is_within_private_site_dir( $path, (int) $snapshot->site_id )
                || ! is_file( $path ) || ! is_readable( $path ) ) {
                return self::refusal( 'unavailable' );
            }
            $real = realpath( $path );
            return false === $real ? self::refusal( 'unavailable' ) : $real;
        }
        return self::refusal( 'unavailable' );
    }

    /**
     * Issue a ten-minute link without admitting a restore or staging remote data.
     *
     * @param int $snapshot_id Selected snapshot.
     * @return string|\WP_Error Download URL with raw query separators.
     */
    public static function create_download_url( int $snapshot_id ): string|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return self::refusal( 'permission' );
        }
        $snapshot = MainWP_Backup_Operations::authorize_snapshot( $snapshot_id );
        if ( is_wp_error( $snapshot ) ) {
            return self::refusal( 'permission' );
        }
        $path = self::local_archive( $snapshot );
        if ( is_wp_error( $path ) ) {
            return $path;
        }
        $token  = wp_generate_password( 32, false );
        $record = array(
            'user_id'     => get_current_user_id(),
            'site_id'     => (int) $snapshot->site_id,
            'snapshot_id' => (int) $snapshot->id,
            'file'        => basename( $path ),
            'expires'     => time() + 600,
        );
        if ( ! set_site_transient( self::TRANSIENT_PREFIX . hash( 'sha256', $token ), $record, 600 ) ) {
            return self::refusal( 'link' );
        }
        return add_query_arg(
            array(
                'action'      => 'mainwp_backup_snapshot_download',
                'token'       => $token,
                'site_id'     => $record['site_id'],
                'snapshot_id' => $record['snapshot_id'],
                'file'        => $record['file'],
                '_wpnonce'    => wp_create_nonce( 'mainwp_backup_snapshot_download' ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Re-authorize and consume the token before returning a file to the streamer.
     *
     * @param array $query Download request.
     * @return string|\WP_Error Authorized canonical path.
     */
    public static function redeem_download( array $query ): string|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return self::refusal( 'permission' );
        }
        foreach ( array( '_wpnonce', 'token', 'site_id', 'snapshot_id', 'file' ) as $field ) {
            if ( ! isset( $query[ $field ] ) || ! is_scalar( $query[ $field ] ) ) {
                return self::refusal( 'link' );
            }
        }
        if ( ! wp_verify_nonce( (string) $query['_wpnonce'], 'mainwp_backup_snapshot_download' )
            || ! preg_match( '/^[a-zA-Z0-9]{32}$/D', (string) $query['token'] ) ) {
            return self::refusal( 'link' );
        }
        $key    = self::TRANSIENT_PREFIX . hash( 'sha256', (string) $query['token'] );
        $record = get_site_transient( $key );
        if ( ! is_array( $record ) || ! isset( $record['user_id'], $record['site_id'], $record['snapshot_id'], $record['file'], $record['expires'] )
            || ! is_int( $record['user_id'] ) || get_current_user_id() !== $record['user_id']
            || ! is_int( $record['expires'] ) || time() >= $record['expires']
            || ! is_int( $record['site_id'] ) || (string) $record['site_id'] !== (string) $query['site_id']
            || ! is_int( $record['snapshot_id'] ) || (string) $record['snapshot_id'] !== (string) $query['snapshot_id']
            || ! is_string( $record['file'] ) || $record['file'] !== $query['file'] ) {
            return self::refusal( 'link' );
        }
        $snapshot = MainWP_Backup_Operations::authorize_snapshot( $record['snapshot_id'] );
        if ( is_wp_error( $snapshot ) ) {
            return self::refusal( 'permission' );
        }
        $path = self::local_archive( $snapshot );
        if ( is_wp_error( $path ) || (int) $snapshot->site_id !== $record['site_id'] || basename( $path ) !== $record['file'] ) {
            return self::refusal( 'link' );
        }
        // A failed deletion means another request has already consumed this token.
        if ( ! delete_site_transient( $key ) ) {
            return self::refusal( 'link' );
        }
        return $path;
    }

    /**
     * Serve the admin endpoint after the token's authorization checks.
     *
     * @return void
     */
    public static function download_snapshot(): void {
        $query = array();
        foreach ( array( '_wpnonce', 'token', 'site_id', 'snapshot_id', 'file' ) as $field ) {
            $query[ $field ] = isset( $_GET[ $field ] ) && is_string( $_GET[ $field ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redeem_download verifies the nonce.
        }
        $path = self::redeem_download( $query );
        if ( is_wp_error( $path ) ) {
            wp_die( esc_html( $path->get_error_message() ), '', array( 'response' => 403 ) );
        }
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        $result = self::stream_file( $path );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
        }
    }

    /**
     * Stream an already authorized file without deleting it or exiting.
     *
     * @param string $path Authorized local file.
     * @return bool|\WP_Error True on completion, false on client abort, error on read failure.
     */
    public static function stream_file( string $path ): bool|\WP_Error {
        // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- bounded binary reads; failures return a fixed error.
        $handle = @fopen( $path, 'rb' );
        if ( false === $handle ) {
            return self::refusal( 'read' );
        }
        $previous = ignore_user_abort( true );
        try {
            $stat = fstat( $handle );
            if ( false === $stat ) {
                return self::refusal( 'read' );
            }
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $path ) ) . '"' );
            header( 'Cache-Control: private, no-store' );
            header( 'Content-Length: ' . $stat['size'] );
            while ( ! feof( $handle ) ) {
                if ( connection_aborted() ) {
                    return false;
                }
                $chunk = fread( $handle, 64 * 1024 );
                if ( false === $chunk ) {
                    return self::refusal( 'read' );
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authorized binary archive bytes.
                if ( ob_get_level() && ob_get_length() ) {
                    ob_flush();
                }
                flush();
            }
            return ! connection_aborted();
        } finally {
            fclose( $handle );
            ignore_user_abort( (bool) $previous );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
    }
}
