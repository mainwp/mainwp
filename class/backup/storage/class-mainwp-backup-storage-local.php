<?php
/**
 * MainWP Backup Storage Local Provider.
 *
 * Stores objects under a configured base path on the dashboard's own
 * filesystem (or anything mounted into it). Writes are atomic via tmp
 * then rename, keys are validated against path traversal, and the
 * connection test proves the base path is writable before a destination
 * saves.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_Local
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_Local implements MainWP_Backup_Storage_Provider { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Base directory all keys resolve under.
     *
     * @var string
     */
    private $base_path;

    /**
     * Constructor.
     *
     * @param array $settings Provider settings: base_path.
     */
    public function __construct( array $settings ) {
        $this->base_path = isset( $settings['base_path'] ) ? rtrim( (string) $settings['base_path'], '/\\' ) : '';
    }

    /**
     * Store an object atomically under the base path.
     *
     * @param string $key          Object key.
     * @param string $body         Object bytes.
     * @param string $content_type Ignored for local storage.
     *
     * @return bool
     * @throws \RuntimeException When the base path is refused.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool {
        unset( $content_type );

        $path = $this->resolve( $key );
        if ( false === $path ) {
            return false;
        }

        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- destination dir outside WP uploads helpers.
            return false;
        }

        $tmp = $path . '.tmp.' . getmypid() . '.' . wp_rand( 1000, 9999 );
        if ( strlen( $body ) !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- destination write outside WP uploads helpers.
            if ( is_file( $tmp ) ) {
                unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup.
            }
            return false;
        }

        if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic publish.
            unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup.
            return false;
        }

        return true;
    }

    /**
     * Check whether an object exists.
     *
     * @param string $key Object key.
     *
     * @return array|false
     * @throws \RuntimeException When the base path is refused.
     */
    public function head_object( string $key ) {
        $path = $this->resolve( $key );
        if ( false === $path || ! is_file( $path ) ) {
            return false;
        }

        clearstatcache( true, $path );
        return array( 'size' => (int) filesize( $path ) );
    }

    /**
     * Copy an object out to a local file.
     *
     * @param string $key        Object key.
     * @param string $local_path Target file path.
     *
     * @return bool
     * @throws \RuntimeException When the base path is refused.
     */
    public function get_object( string $key, string $local_path ): bool {
        $path = $this->resolve( $key );
        if ( false === $path || ! is_file( $path ) ) {
            return false;
        }

        return copy( $path, $local_path );
    }

    /**
     * Delete an object; deleting a missing object succeeds.
     *
     * @param string $key Object key.
     *
     * @return bool
     * @throws \RuntimeException When the base path is refused.
     */
    public function delete_object( string $key ): bool {
        $path = $this->resolve( $key );
        if ( false === $path ) {
            return false;
        }

        if ( ! is_file( $path ) ) {
            return true;
        }

        return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- destination delete outside WP uploads helpers.
    }

    /**
     * List objects under a prefix. Local listings are never paginated.
     *
     * The walk prunes directories that cannot contain the prefix, so a
     * per-snapshot listing never scans other sites' backup history under
     * the same base path.
     *
     * @param string      $prefix Key prefix filter.
     * @param string|null $token  Ignored.
     *
     * @return array
     * @throws \RuntimeException When the base path is refused.
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array {
        unset( $token );

        if ( '' !== $this->base_path ) {
            $reason = MainWP_Backup_Private_Root::served_tree_refusal( $this->base_path );
            if ( '' !== $reason ) {
                throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Consumers escape the plain-text refusal at output.
            }
        }

        $objects = array();

        if ( is_dir( $this->base_path ) ) {
            $base_len = strlen( $this->base_path ) + 1;
            $pruned   = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator( $this->base_path, \FilesystemIterator::SKIP_DOTS ),
                static function ( $file ) use ( $prefix, $base_len ) {
                    if ( '' === $prefix || ! $file->isDir() ) {
                        return true;
                    }
                    // Descend only into ancestors of the prefix subtree or
                    // directories inside it.
                    $key = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), $base_len ) ) . '/';
                    return 0 === strpos( $prefix, $key ) || 0 === strpos( $key, $prefix );
                }
            );
            $iterator = new \RecursiveIteratorIterator( $pruned );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }
                $key = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), $base_len ) );
                if ( '' !== $prefix && 0 !== strpos( $key, $prefix ) ) {
                    continue;
                }
                $objects[] = array(
                    'key'  => $key,
                    'size' => (int) $file->getSize(),
                );
            }
        }

        return array(
            'objects'    => $objects,
            'next_token' => null,
            'truncated'  => false,
        );
    }

    /**
     * Prove the base path exists and is writable with a probe file.
     *
     * @return array
     */
    public function connection_test(): array {
        if ( '' !== $this->base_path ) {
            $reason = MainWP_Backup_Private_Root::served_tree_refusal( $this->base_path );
            if ( '' !== $reason ) {
                return array(
                    'ok'    => false,
                    'error' => $reason,
                );
            }
        }

        if ( '' === $this->base_path || ! is_dir( $this->base_path ) ) {
            return array(
                'ok'    => false,
                'error' => 'Base path does not exist.',
            );
        }

        $probe = '.mainwp-probe-' . uniqid() . '.txt';
        if ( ! $this->put_object( $probe, 'probe' ) ) {
            return array(
                'ok'    => false,
                'error' => 'Base path is not writable.',
            );
        }
        $this->delete_object( $probe );

        return array(
            'ok'    => true,
            'error' => null,
        );
    }

    /**
     * Resolve a key to an absolute path under the base path, rejecting
     * traversal.
     *
     * @param string $key Object key.
     *
     * @return string|false
     * @throws \RuntimeException When the base path is refused.
     */
    private function resolve( string $key ) {
        if ( '' === $this->base_path || '' === $key ) {
            return false;
        }

        $reason = MainWP_Backup_Private_Root::served_tree_refusal( $this->base_path );
        if ( '' !== $reason ) {
            throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Consumers escape the plain-text refusal at output.
        }

        $key = str_replace( '\\', '/', $key );
        foreach ( explode( '/', $key ) as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                return false;
            }
        }

        $path   = $this->base_path . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $key );
        $reason = MainWP_Backup_Private_Root::served_tree_refusal( $path );
        if ( '' !== $reason ) {
            throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Consumers escape the plain-text refusal at output.
        }

        return $path;
    }
}
