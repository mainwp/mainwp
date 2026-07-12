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
     * Option holding the trust-on-first-use host-key fingerprints, keyed
     * by destination id. Fingerprints are public values (hashes of the
     * server's public key), so they live outside the encrypted settings
     * envelope and survive without a re-encryption round trip.
     *
     * @var string
     */
    const HOSTKEYS_OPTION = 'mainwp_backup_sftp_hostkeys';

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

        // Re-check the host right before connecting so a DNS rebind cannot
        // redirect the transfer to a link-local / cloud-metadata address. The
        // connection is opened once per operation, so no per-request cache is
        // needed here.
        if ( '' !== $host && ! MainWP_Backup_Storage_Destinations::is_safe_host( $host ) ) {
            throw new \RuntimeException( 'Storage destination host resolves to a link-local or cloud-metadata address: ' . esc_html( $host ), 0 );
        }

        $sftp = new \phpseclib3\Net\SFTP( $host, $port, static::CONNECT_TIMEOUT );

        // Verify the server's identity BEFORE any credential is offered: a
        // mismatch must fail the handshake, not hand the password to an
        // interceptor first.
        $this->verify_host_key( $sftp );

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
     * Verify the server's host key against the pinned or learned
     * fingerprint, trust-on-first-use.
     *
     * A manual `host_fingerprint` setting is the authority when present.
     * Otherwise the fingerprint learned on the destination's first
     * successful handshake is required on every later connect; the first
     * handshake of a saved destination stores it. A connection without a
     * destination id (a probe before the row exists) verifies a manual
     * pin but learns nothing.
     *
     * @param object $sftp Connection exposing getServerPublicHostKey().
     *
     * @throws \RuntimeException When the server key cannot be read, or the
     *                           fingerprint does not match the pin (401 so
     *                           the destination parks instead of retrying
     *                           into a possible interception).
     */
    public function verify_host_key( $sftp ): void {
        $server_key = $sftp->getServerPublicHostKey();
        if ( ! is_string( $server_key ) || '' === $server_key ) {
            throw new \RuntimeException( 'The SFTP server did not present a host key.', 0 );
        }

        $offered = static::fingerprint_host_key( $server_key );
        $manual  = isset( $this->settings['host_fingerprint'] ) ? trim( (string) $this->settings['host_fingerprint'] ) : '';

        if ( '' !== $manual ) {
            if ( ! hash_equals( static::normalize_fingerprint( $manual ), static::normalize_fingerprint( $offered ) ) ) {
                throw new \RuntimeException( esc_html( 'The SFTP server\'s host key does not match the pinned fingerprint. Pinned: ' . $manual . ' - offered: ' . $offered . '. If the server key was intentionally rotated, update the fingerprint in the destination settings.' ), 401 );
            }
            return;
        }

        $destination_id = isset( $this->settings['destination_id'] ) ? (int) $this->settings['destination_id'] : 0;
        if ( $destination_id <= 0 ) {
            return; // Nothing to pin against and nowhere to learn to.
        }

        $known = get_option( static::HOSTKEYS_OPTION );
        $known = is_array( $known ) ? $known : array();

        if ( ! isset( $known[ $destination_id ] ) ) {
            // First use: trust and remember - via a compare-and-swap on the
            // stored row, so two simultaneous first connects (to the same or
            // different destinations) cannot clobber each other's pins:
            // whoever lands first decides trust, and the loser verifies
            // against that pin below. The per-request options cache cannot
            // see a concurrent process's write, so the CAS reads and writes
            // the row directly. The write MUST land - a silently dropped pin
            // would let the next connect re-learn (and trust) a key that
            // could be an interceptor's, so persistent failure fails the
            // handshake.
            $known[ $destination_id ] = $this->record_first_use_pin( $destination_id, $offered );
        }

        if ( ! hash_equals( static::normalize_fingerprint( (string) $known[ $destination_id ] ), static::normalize_fingerprint( $offered ) ) ) {
            throw new \RuntimeException( esc_html( 'The SFTP server\'s host key changed. Known: ' . $known[ $destination_id ] . ' - offered: ' . $offered . '. This can mean the connection is being intercepted; if the server key was intentionally rotated, re-save the destination to trust the new key.' ), 401 );
        }
    }

    /**
     * Record a first-use pin with compare-and-swap semantics, returning
     * the authoritative pin for the destination: this connection's offered
     * fingerprint when its write lands, or the pin a concurrent connection
     * recorded first, which the caller then verifies against.
     *
     * @param int    $destination_id Destination id.
     * @param string $offered        Offered fingerprint.
     *
     * @return string The authoritative pin for the destination.
     *
     * @throws \RuntimeException When the pin cannot be recorded.
     */
    private function record_first_use_pin( int $destination_id, string $offered ): string {
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $raw   = $this->hostkeys_raw_read();
            $known = null !== $raw ? maybe_unserialize( $raw ) : array();
            $known = is_array( $known ) ? $known : array();

            if ( isset( $known[ $destination_id ] ) ) {
                // A concurrent connection already decided this destination's
                // trust; defer to it rather than racing a second value in.
                return (string) $known[ $destination_id ];
            }

            $known[ $destination_id ] = $offered;
            if ( $this->hostkeys_cas_write( $raw, maybe_serialize( $known ) ) ) {
                return $offered;
            }
            // Lost the swap to another writer; re-read and retry.
        }

        throw new \RuntimeException( 'The SFTP host key could not be recorded for verification; refusing to continue without a trusted pin.', 0 );
    }

    /**
     * Read the stored host-key option's raw row value, bypassing the
     * per-request options cache a concurrent process's write is invisible
     * to.
     *
     * @return string|null Raw serialized value, or null when no row exists.
     */
    protected function hostkeys_raw_read(): ?string {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", static::HOSTKEYS_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- CAS read must see concurrent writers past the options cache.
        return null === $raw ? null : (string) $raw;
    }

    /**
     * One compare-and-swap write of the host-key option row (test seam):
     * an atomic INSERT claims a missing row, a guarded UPDATE replaces
     * exactly the value this connection read.
     *
     * @param string|null $expected_raw Raw value the row held at read time,
     *                                  null when no row existed.
     * @param string      $next_raw     Raw value to store.
     *
     * @return bool True when this writer's value landed.
     */
    protected function hostkeys_cas_write( ?string $expected_raw, string $next_raw ): bool {
        global $wpdb;
        if ( null === $expected_raw ) {
            $claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", static::HOSTKEYS_OPTION, $next_raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic first-writer claim; must hit the DB engine.
        } else {
            $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next_raw, static::HOSTKEYS_OPTION, $expected_raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the compare-and-swap; must hit the DB engine.
        }

        if ( 1 === (int) $claimed ) {
            wp_cache_delete( static::HOSTKEYS_OPTION, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            // A get_option() that ran before the row existed cached the
            // option as missing; without clearing that, later reads keep
            // returning false past the direct-DB insert.
            wp_cache_delete( 'notoptions', 'options' );
            return true;
        }
        return false;
    }

    /**
     * The OpenSSH SHA256 fingerprint of a host key string, comparable
     * with `ssh-keygen -lf` output: the base64 key blob is decoded and
     * hashed, not the textual line.
     *
     * @param string $server_key Host key string (algorithm + base64 blob).
     *
     * @return string Fingerprint in SHA256:base64 form.
     */
    public static function fingerprint_host_key( string $server_key ): string {
        $parts = explode( ' ', trim( $server_key ) );
        $blob  = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the SSH wire-format key blob, per RFC 4253.
        if ( false === $blob || '' === $blob ) {
            $blob = $server_key; // Unparseable key strings still fingerprint deterministically.
        }
        return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OpenSSH's own fingerprint encoding.
    }

    /**
     * Normalize a fingerprint for comparison: optional SHA256: prefix and
     * base64 padding are cosmetic.
     *
     * @param string $fingerprint Fingerprint.
     *
     * @return string
     */
    private static function normalize_fingerprint( string $fingerprint ): string {
        $fingerprint = trim( $fingerprint );
        if ( 0 === stripos( $fingerprint, 'SHA256:' ) ) {
            $fingerprint = substr( $fingerprint, 7 );
        }
        return rtrim( $fingerprint, '=' );
    }

    /**
     * Forget the learned host-key fingerprint for a destination - called
     * when the destination is edited (the host may have changed; the next
     * connect re-learns) or deleted.
     *
     * @param int $destination_id Destination id.
     */
    public static function forget_host_key( int $destination_id ): void {
        ( new self( array() ) )->forget_host_key_cas( $destination_id );
    }

    /**
     * Compare-and-swap removal of one destination's learned pin, routed
     * through the same direct-read/guarded-write the pin writes use so a
     * concurrent record_first_use_pin() for a different destination is not
     * clobbered by a stale read-modify-write. Mirrors that method's retry
     * loop: a lost swap re-reads and retries; a missing row or an already
     * absent pin is a no-op. When every swap loses (a dead or read-only DB),
     * the stale pin is left standing rather than force-written - the next
     * connect then refuses on host-key-change and the user re-saves, the
     * fail-closed direction.
     *
     * @param int $destination_id Destination id.
     *
     * @return void
     */
    private function forget_host_key_cas( int $destination_id ): void {
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $raw = $this->hostkeys_raw_read();
            if ( null === $raw ) {
                return; // No row: nothing to forget.
            }

            $known = maybe_unserialize( $raw );
            $known = is_array( $known ) ? $known : array();
            if ( ! isset( $known[ $destination_id ] ) ) {
                return; // Pin already gone.
            }

            unset( $known[ $destination_id ] );
            if ( $this->hostkeys_cas_write( $raw, maybe_serialize( $known ) ) ) {
                return;
            }
            // Lost the swap to a concurrent writer; re-read and retry.
        }
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
                // Descend only into ancestors of the prefix subtree or
                // directories inside it, so a per-snapshot listing never
                // scans the destination's whole backup history.
                $dir_key = $key . '/';
                if ( '' === $prefix || 0 === strpos( $prefix, $dir_key ) || 0 === strpos( $dir_key, $prefix ) ) {
                    $this->walk( $sftp, $dir . '/' . $name, $key, $prefix, $objects );
                }
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
