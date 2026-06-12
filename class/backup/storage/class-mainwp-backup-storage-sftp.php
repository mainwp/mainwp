<?php
/**
 * MainWP Backup Storage SFTP Provider.
 *
 * Stores objects on an SFTP server through the bundled phpseclib3
 * library. Authentication takes a password, a private key, or a
 * passphrase-protected private key (the password field doubles as the
 * passphrase when a key is set). Keys resolve under the configured base
 * path with traversal segments rejected, uploads create parent
 * directories and publish via tmp-then-rename, and listing walks the
 * tree recursively without pagination, matching the Local provider.
 * Every method throws \RuntimeException on failure; login rejections
 * carry 401 as the code so the upload driver can park the destination
 * as credentials-invalid, everything else carries 0 and retries.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_SFTP
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_SFTP implements MainWP_Backup_Storage_Provider { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * SFTP attribute type for regular files.
     *
     * @var int
     */
    const TYPE_FILE = 1;

    /**
     * SFTP attribute type for directories.
     *
     * @var int
     */
    const TYPE_DIRECTORY = 2;

    /**
     * Connection timeout in seconds.
     *
     * @var int
     */
    const CONNECT_TIMEOUT = 30;

    /**
     * Provider settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Connection factory: fn(): object (a logged-in SFTP connection).
     *
     * @var callable
     */
    private $connection_factory;

    /**
     * The lazily opened connection.
     *
     * @var object|null
     */
    private $connection = null;

    /**
     * Normalized base path all keys resolve under.
     *
     * @var string
     */
    private $base_path;

    /**
     * Constructor.
     *
     * @param array         $settings           Provider settings: host, port, username, password, private_key, base_path.
     * @param callable|null $connection_factory Connection factory override for tests.
     */
    public function __construct( array $settings, $connection_factory = null ) {
        $this->settings           = $settings;
        $this->base_path          = isset( $settings['base_path'] ) ? rtrim( (string) $settings['base_path'], '/' ) : '';
        $this->connection_factory = is_callable( $connection_factory ) ? $connection_factory : array( $this, 'default_connection' );
    }

    /**
     * Open and authenticate the default phpseclib3 connection.
     *
     * @return object
     *
     * @throws \RuntimeException When the login is rejected (401) or the key cannot load.
     */
    public function default_connection() {
        MainWP_Keys_Manager::auto_load_files();

        $host = isset( $this->settings['host'] ) ? (string) $this->settings['host'] : '';
        $port = ! empty( $this->settings['port'] ) ? (int) $this->settings['port'] : 22;

        $sftp = new \phpseclib3\Net\SFTP( $host, $port, static::CONNECT_TIMEOUT );

        $username    = isset( $this->settings['username'] ) ? (string) $this->settings['username'] : '';
        $password    = isset( $this->settings['password'] ) ? (string) $this->settings['password'] : '';
        $private_key = isset( $this->settings['private_key'] ) ? (string) $this->settings['private_key'] : '';

        if ( '' !== trim( $private_key ) ) {
            try {
                $credential = \phpseclib3\Crypt\PublicKeyLoader::load( $private_key, '' !== $password ? $password : false );
            } catch ( \Throwable $e ) {
                throw new \RuntimeException( 'The SFTP private key could not be loaded.', 401 );
            }
        } else {
            $credential = $password;
        }

        if ( ! $sftp->login( $username, $credential ) ) {
            throw new \RuntimeException( 'The SFTP server rejected the login.', 401 );
        }

        return $sftp;
    }

    /**
     * Store an object: parents created, published via tmp-then-rename.
     *
     * @param string $key          Object key.
     * @param string $body         Object bytes.
     * @param string $content_type Ignored for SFTP.
     *
     * @return bool
     *
     * @throws \RuntimeException On connection or write failures.
     */
    public function put_object( string $key, string $body, string $content_type = 'application/octet-stream' ): bool {
        unset( $content_type );

        $path = $this->resolve( $key );
        $sftp = $this->connection();

        $dir = substr( $path, 0, (int) strrpos( $path, '/' ) );
        if ( '' !== $dir && ! $sftp->is_dir( $dir ) && ! $sftp->mkdir( $dir, -1, true ) ) {
            throw new \RuntimeException( esc_html( 'Storage request PUT ' . $key . ' could not create the remote directory.' ), 0 );
        }

        $tmp = $path . '.tmp-' . getmypid() . '-' . wp_rand( 1000, 9999 );
        if ( ! $sftp->put( $tmp, $body ) ) {
            throw new \RuntimeException( esc_html( 'Storage request PUT ' . $key . ' failed to write.' ), 0 );
        }

        if ( $sftp->file_exists( $path ) ) {
            $sftp->delete( $path, false );
        }

        if ( ! $sftp->rename( $tmp, $path ) ) {
            $sftp->delete( $tmp, false );
            throw new \RuntimeException( esc_html( 'Storage request PUT ' . $key . ' failed to publish.' ), 0 );
        }

        return true;
    }

    /**
     * Check whether an object exists; directories are not objects.
     *
     * @param string $key Object key.
     *
     * @return array|false
     *
     * @throws \RuntimeException On connection failures.
     */
    public function head_object( string $key ) {
        $stat = $this->connection()->stat( $this->resolve( $key ) );

        if ( ! is_array( $stat ) || static::TYPE_FILE !== (int) ( $stat['type'] ?? 0 ) ) {
            return false;
        }

        return array( 'size' => (int) ( $stat['size'] ?? 0 ) );
    }

    /**
     * Download an object to a local file.
     *
     * @param string $key        Object key.
     * @param string $local_path Target file path.
     *
     * @return bool
     *
     * @throws \RuntimeException On connection or read failures.
     */
    public function get_object( string $key, string $local_path ): bool {
        if ( ! $this->connection()->get( $this->resolve( $key ), $local_path ) ) {
            throw new \RuntimeException( esc_html( 'Storage request GET ' . $key . ' failed.' ), 0 );
        }

        return true;
    }

    /**
     * Delete an object; a missing object counts as deleted.
     *
     * @param string $key Object key.
     *
     * @return bool
     *
     * @throws \RuntimeException On connection or delete failures.
     */
    public function delete_object( string $key ): bool {
        $path = $this->resolve( $key );
        $sftp = $this->connection();

        if ( ! $sftp->file_exists( $path ) ) {
            return true;
        }

        if ( ! $sftp->delete( $path, false ) ) {
            throw new \RuntimeException( esc_html( 'Storage request DELETE ' . $key . ' failed.' ), 0 );
        }

        return true;
    }

    /**
     * List objects under a prefix by walking the tree. SFTP listings
     * are never paginated, matching the Local provider.
     *
     * @param string      $prefix Key prefix filter.
     * @param string|null $token  Ignored.
     *
     * @return array
     *
     * @throws \RuntimeException On connection failures.
     */
    public function list_objects( string $prefix = '', ?string $token = null ): array {
        unset( $token );

        $sftp    = $this->connection();
        $objects = array();

        if ( '' !== $this->base_path && $sftp->is_dir( $this->base_path ) ) {
            $this->walk( $sftp, $this->base_path, '', $prefix, $objects );
        }

        return array(
            'objects'    => $objects,
            'next_token' => null,
            'truncated'  => false,
        );
    }

    /**
     * Prove the base path exists and is writable with a probe object.
     *
     * @return array
     *
     * @throws \RuntimeException Internally on a missing probe; always caught and reported in the result.
     */
    public function connection_test(): array {
        try {
            if ( '' === $this->base_path || ! $this->connection()->is_dir( $this->base_path ) ) {
                return array(
                    'ok'    => false,
                    'error' => 'Base path does not exist on the SFTP server.',
                );
            }

            $probe = '.mainwp-probe-' . uniqid() . '.txt';

            $this->put_object( $probe, 'mainwp-connection-test' );

            if ( false === $this->head_object( $probe ) ) {
                throw new \RuntimeException( 'Probe object missing after upload.', 0 );
            }

            $this->delete_object( $probe );
        } catch ( \RuntimeException $e ) {
            return array(
                'ok'    => false,
                'error' => $e->getMessage() . ( $e->getCode() ? ' (HTTP ' . $e->getCode() . ')' : '' ),
            );
        }

        return array(
            'ok'    => true,
            'error' => null,
        );
    }

    /**
     * The open connection, from the factory on first use.
     *
     * @return object
     *
     * @throws \RuntimeException When the factory fails; non-runtime failures normalize to code 0.
     */
    private function connection() {
        if ( null !== $this->connection ) {
            return $this->connection;
        }

        try {
            $this->connection = call_user_func( $this->connection_factory );
        } catch ( \RuntimeException $e ) {
            throw $e;
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'Storage connection failed: ' . esc_html( $e->getMessage() ), 0 );
        }

        return $this->connection;
    }

    /**
     * Recursively collect file entries under a directory.
     *
     * @param object $sftp     Open connection.
     * @param string $dir      Absolute remote directory.
     * @param string $relative Key prefix of the directory relative to the base path.
     * @param string $prefix   Requested key prefix filter.
     * @param array  $objects  Collected objects, by reference.
     *
     * @return void
     */
    private function walk( $sftp, string $dir, string $relative, string $prefix, array &$objects ): void {
        $entries = $sftp->rawlist( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }

        foreach ( $entries as $name => $attrs ) {
            if ( '.' === $name || '..' === $name || ! is_array( $attrs ) ) {
                continue;
            }

            $key  = '' === $relative ? (string) $name : $relative . '/' . $name;
            $type = (int) ( $attrs['type'] ?? 0 );

            if ( static::TYPE_DIRECTORY === $type ) {
                $this->walk( $sftp, $dir . '/' . $name, $key, $prefix, $objects );
                continue;
            }

            if ( static::TYPE_FILE !== $type ) {
                continue;
            }

            if ( '' !== $prefix && 0 !== strpos( $key, $prefix ) ) {
                continue;
            }

            $objects[] = array(
                'key'  => $key,
                'size' => (int) ( $attrs['size'] ?? 0 ),
            );
        }
    }

    /**
     * Resolve a key to an absolute remote path under the base path,
     * rejecting traversal.
     *
     * @param string $key Object key.
     *
     * @return string
     *
     * @throws \RuntimeException For an empty base path or a traversal key.
     */
    private function resolve( string $key ): string {
        if ( '' === $this->base_path || '' === $key ) {
            throw new \RuntimeException( 'Storage request rejected: no base path or empty key.', 0 );
        }

        $key = str_replace( '\\', '/', $key );
        foreach ( explode( '/', $key ) as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                throw new \RuntimeException( 'Storage request rejected: invalid key.', 0 );
            }
        }

        return $this->base_path . '/' . $key;
    }
}
