<?php
/**
 * MainWP Backup Storage Destinations.
 *
 * CRUD over the configured cloud storage destinations. Settings encrypt
 * on every write through MainWP_Credential_Storage - the same split-key
 * envelope system (MWP-1548) that protects site HTTP credentials, the
 * MainWP API key, and the api-backups module's third-party credentials:
 * ciphertext in the database, per-secret key material in the private
 * pk/ directory, so a database-only compromise yields nothing. Settings
 * decrypt only on single-row reads; list views never carry settings at
 * all. A settings blob that no longer decrypts (lost key file,
 * tampering) loads with null settings and a credentials_invalid flag so
 * the UI can ask for the credentials again instead of failing or
 * leaking garbage downstream. Key files are retired when their
 * destination is deleted or its settings re-encrypt.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Storage_Destinations
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Storage_Destinations extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Storage_Destinations or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Storage_Destinations
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Add a storage destination.
     *
     * @param array $data {
     *     Destination fields.
     *
     *     @type string $provider   Provider slug, e.g. 's3' or 'local'.
     *     @type string $name       Display name.
     *     @type array  $settings   Provider settings; encrypted before storage.
     *     @type int    $is_default Whether this is the preselected destination.
     * }
     *
     * @return int Destination id, 0 on failure.
     */
    public function add_destination( array $data ): int {
        $intent = $this->stage_settings_encryption( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array() );
        if ( false === $intent ) {
            return 0;
        }

        $is_default = empty( $data['is_default'] ) ? 0 : 1;
        if ( $is_default ) {
            $this->clear_default();
        }

        $inserted = $this->wpdb->insert(
            $this->table_name( 'wp_backup_storage' ),
            array(
                'provider'   => isset( $data['provider'] ) ? (string) $data['provider'] : '',
                'name'       => isset( $data['name'] ) ? (string) $data['name'] : '',
                'settings'   => $intent['value'],
                'is_default' => $is_default,
                'created'    => time(),
                'updated'    => time(),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%d' )
        );

        if ( ! $inserted ) {
            // The row never landed: delete the key file the staging
            // minted instead of orphaning it.
            MainWP_Credential_Storage::rollback_credential_intent( $intent );
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Update a storage destination. Settings re-encrypt when provided and
     * stay untouched otherwise.
     *
     * @param int   $id   Destination id.
     * @param array $data Fields to update: provider, name, settings, is_default.
     *
     * @return bool
     */
    public function update_destination( int $id, array $data ): bool {
        $row    = array();
        $format = array();

        foreach ( array( 'provider', 'name' ) as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $row[ $field ] = (string) $data[ $field ];
                $format[]      = '%s';
            }
        }

        $old_file_key    = '';
        $settings_intent = null;
        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
            $settings_intent = $this->stage_settings_encryption( $data['settings'] );
            if ( false === $settings_intent ) {
                return false;
            }
            $row['settings'] = $settings_intent['value'];
            $format[]        = '%s';

            $old_file_key = $this->stored_file_key( $id );
        }

        if ( isset( $data['is_default'] ) ) {
            $is_default = empty( $data['is_default'] ) ? 0 : 1;
            if ( $is_default ) {
                $this->clear_default();
            }
            $row['is_default'] = $is_default;
            $format[]          = '%d';
        }

        if ( empty( $row ) ) {
            return false;
        }

        $row['updated'] = time();
        $format[]       = '%d';

        $updated = $this->wpdb->update(
            $this->table_name( 'wp_backup_storage' ),
            $row,
            array( 'id' => $id ),
            $format,
            array( '%d' )
        );

        if ( false === $updated && null !== $settings_intent ) {
            // The row update never landed: the stored envelope still
            // references the old key file, so the freshly minted one is
            // deleted instead of orphaned - and the old one survives.
            MainWP_Credential_Storage::rollback_credential_intent( $settings_intent );
        }

        if ( false !== $updated && '' !== $old_file_key && isset( $row['settings'] ) && $old_file_key !== $this->envelope_file_key( (string) $row['settings'] ) ) {
            // The re-encrypted settings sealed under a fresh key file;
            // retire the superseded one so pk/ never accumulates key
            // material for ciphertext that no longer exists.
            MainWP_Keys_Manager::instance()->delete_key_file( $old_file_key );
        }

        if ( false !== $updated && isset( $data['settings'] ) ) {
            // Edited settings may point at a different server; drop the
            // learned host-key pin so the next connect re-learns instead of
            // refusing the legitimate new host.
            MainWP_Backup_Storage_SFTP::forget_host_key( $id );
        }

        return false !== $updated;
    }

    /**
     * Delete a storage destination and retire its key file.
     *
     * @param int $id Destination id.
     *
     * @return bool
     */
    public function delete_destination( int $id ): bool {
        $file_key = $this->stored_file_key( $id );

        $deleted = (bool) $this->wpdb->delete( $this->table_name( 'wp_backup_storage' ), array( 'id' => $id ), array( '%d' ) );

        if ( $deleted && '' !== $file_key ) {
            MainWP_Keys_Manager::instance()->delete_key_file( $file_key );
        }
        if ( $deleted ) {
            MainWP_Backup_Storage_SFTP::forget_host_key( $id );
        }

        return $deleted;
    }

    /**
     * Get a destination with settings decrypted. Undecryptable settings
     * load as null with credentials_invalid set.
     *
     * @param int $id Destination id.
     *
     * @return object|null
     */
    public function get_destination( int $id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE id = %d',
                $id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Get the default destination, if one is marked.
     *
     * @return object|null
     */
    public function get_default(): ?object {
        $row = $this->wpdb->get_row(
            'SELECT * FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE is_default = 1 ORDER BY id DESC LIMIT 1'
        );
        return $this->decode_row( $row );
    }

    /**
     * List destinations without settings. The list view never needs the
     * credentials, so they never leave the column here.
     *
     * @return object[]
     */
    public function list_destinations(): array {
        $rows = $this->wpdb->get_results(
            'SELECT id, provider, name, is_default, created, updated FROM ' . $this->table_name( 'wp_backup_storage' ) . ' ORDER BY name ASC'
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Build the provider for a destination row.
     *
     * @param object $row Destination row with decrypted settings.
     *
     * @return MainWP_Backup_Storage_Provider
     *
     * @throws \RuntimeException For an unknown provider slug.
     */
    public function make_provider( object $row ): MainWP_Backup_Storage_Provider {
        $settings = isset( $row->settings ) && is_array( $row->settings ) ? $row->settings : array();

        // The SFTP provider's trust-on-first-use host-key store is keyed by
        // destination id; the settings envelope itself never carries one.
        $settings['destination_id'] = isset( $row->id ) ? (int) $row->id : 0;

        switch ( (string) $row->provider ) {
            case 'local':
                return new MainWP_Backup_Storage_Local( $settings );
            case 's3':
                return new MainWP_Backup_Storage_S3( $settings );
            case 'gcs':
                return new MainWP_Backup_Storage_GCS( $settings );
            case 'dropbox':
                return new MainWP_Backup_Storage_Dropbox( $settings );
            case 'sftp':
                return new MainWP_Backup_Storage_SFTP( $settings );
            default:
                throw new \RuntimeException( esc_html( 'Unknown storage provider: ' . $row->provider ), 0 );
        }
    }

    /**
     * Encrypt a settings array for storage.
     *
     * @param array $settings Provider settings.
     *
     * @return array|false Staged credential intent (value/retire_key/fresh_key),
     *                     or false when encryption is unavailable. The caller
     *                     persists the value, then settles the intent by
     *                     outcome: rollback deletes the freshly minted key
     *                     file when the write never landed.
     */
    private function stage_settings_encryption( array $settings ) {
        $json = wp_json_encode( $settings );
        if ( false === $json ) {
            return false;
        }
        $intent = MainWP_Credential_Storage::stage_credential_encryption( $json, 'storage_destination' );
        if ( false === $intent || ! is_string( $intent['value'] ) || ! MainWP_Credential_Storage::is_credential_envelope( $intent['value'] ) ) {
            MainWP_Credential_Storage::rollback_credential_intent( $intent );
            return false;
        }
        return $intent;
    }

    /**
     * Clear the default flag across all destinations.
     *
     * @return void
     */
    private function clear_default(): void {
        $this->wpdb->query( 'UPDATE ' . $this->table_name( 'wp_backup_storage' ) . ' SET is_default = 0 WHERE is_default = 1' );
    }

    /**
     * Decrypt and decode the settings on a destination row.
     *
     * Settings are never plaintext-passthrough: a value that is not a
     * credential envelope is unreadable by definition, because every
     * write path encrypts.
     *
     * @param object|null $row Raw destination row.
     *
     * @return object|null Row with settings as array|null and a credentials_invalid flag.
     */
    private function decode_row( ?object $row ): ?object {
        if ( null === $row ) {
            return null;
        }

        $row->credentials_invalid = false;

        $stored    = (string) $row->settings;
        $plaintext = false;
        if ( MainWP_Credential_Storage::is_credential_envelope( $stored ) ) {
            $decrypted = MainWP_Credential_Storage::decrypt_credential( $stored );
            $plaintext = '' === $decrypted ? false : $decrypted;
        }

        if ( false === $plaintext ) {
            $row->settings            = null;
            $row->credentials_invalid = true;
            return $row;
        }

        $decoded       = json_decode( $plaintext, true );
        $row->settings = is_array( $decoded ) ? $decoded : null;
        if ( null === $row->settings ) {
            $row->credentials_invalid = true;
        }

        return $row;
    }

    /**
     * The key-file name referenced by a destination's stored envelope.
     *
     * @param int $id Destination id.
     *
     * @return string Key-file name, '' when none.
     */
    private function stored_file_key( int $id ): string {
        $stored = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT settings FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE id = %d',
                $id
            )
        );
        return $this->envelope_file_key( (string) $stored );
    }

    /**
     * Extract the key-file name from a credential envelope.
     *
     * @param string $stored Raw settings column value.
     *
     * @return string Key-file name, '' when not an envelope.
     */
    private function envelope_file_key( string $stored ): string {
        if ( ! MainWP_Credential_Storage::is_credential_envelope( $stored ) ) {
            return '';
        }
        $envelope = json_decode( substr( $stored, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
        return is_array( $envelope ) && ! empty( $envelope['file_key'] ) ? (string) $envelope['file_key'] : '';
    }

    /**
     * Whether a storage destination host is safe to connect to right before a
     * transfer. This re-checks the host at request time so a DNS rebind between
     * config-save and use cannot silently redirect a backup to an internal
     * service; it narrows the rebind window rather than pinning the IP.
     *
     * Scope is deliberately narrow: only the never-legitimate, high-impact
     * ranges are blocked - IPv4 link-local 169.254.0.0/16 (which carries the
     * AWS/GCP/Azure cloud-metadata endpoint 169.254.169.254) and IPv6
     * link-local fe80::/10. Loopback and RFC1918 are ALLOWED, because
     * self-hosted storage (MinIO on 127.0.0.1, a NAS on the LAN) is a supported
     * configuration and blocking it would break legitimate backups. An
     * unresolvable host fails open - a DNS blip is not evidence of a rebind.
     *
     * @param string $host Hostname or IP literal.
     *
     * @return bool True when safe to connect (or unresolvable), false when it
     *              resolves to a link-local / cloud-metadata address.
     */
    public static function is_safe_host( string $host ): bool {
        $host = trim( $host );
        if ( '' === $host ) {
            return true;
        }

        /**
         * Filter: bypass the outbound SSRF host check for a destination that
         * must reach an otherwise-blocked address.
         *
         * @param bool   $skip Whether to skip the check. Default false.
         * @param string $host The host being checked.
         */
        if ( apply_filters( 'mainwp_backup_skip_ssrf_check', false, $host ) ) {
            return true;
        }

        // An IP literal is validated directly: dns_get_record() on an IP
        // returns nothing, which would otherwise fail open on a raw metadata IP.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ! self::is_blocked_ip( $host );
        }

        $ips = self::resolve_host_ips( $host );
        if ( empty( $ips ) ) {
            return true; // Unresolvable: fail open, let the real connection fail on its own.
        }

        foreach ( $ips as $ip ) {
            if ( self::is_blocked_ip( $ip ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to its IPv4 and IPv6 addresses.
     *
     * When the DNS query yields nothing, gethostbynamel() runs as a fallback:
     * it goes through the system resolver (nsswitch), so it covers hosts that
     * only exist in /etc/hosts and resolvers that answer an A query with a
     * bare CNAME record. The fallback is IPv4-only - PHP has no hosts-file-aware
     * AAAA lookup - so a hosts-file mapping to an IPv6 target (fe80::/10,
     * fd00:ec2::254) still fails open; accepted, since writing /etc/hosts
     * already requires control of the dashboard host itself.
     *
     * @param string $host Hostname.
     *
     * @return string[] Resolved IPs, empty when the host does not resolve.
     */
    private static function resolve_host_ips( string $host ): array {
        $ips     = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a resolution failure is handled by the caller (fail open).
        if ( is_array( $records ) ) {
            foreach ( $records as $record ) {
                if ( ! empty( $record['ip'] ) ) {
                    $ips[] = (string) $record['ip'];
                } elseif ( ! empty( $record['ipv6'] ) ) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        if ( empty( $ips ) ) {
            $fallback = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a resolution failure is handled by the caller (fail open).
            if ( is_array( $fallback ) ) {
                $ips = array_map( 'strval', $fallback );
            }
        }

        /**
         * Filter: the resolved IPs for a storage destination host, before the
         * SSRF block-list check runs against them.
         *
         * @param string[] $ips  Resolved IPs; empty means unresolvable (fail open).
         * @param string   $host The host that was resolved.
         */
        return apply_filters( 'mainwp_backup_resolved_host_ips', $ips, $host );
    }

    /**
     * Whether an IP is a never-legitimate, high-impact target that must be
     * blocked even though other private ranges (loopback, RFC1918, IPv6 ULA)
     * are allowed for self-hosted storage:
     *  - IPv4 169.254.0.0/16 link-local, which carries the AWS/GCP/Azure
     *    metadata endpoint 169.254.169.254 (and its IPv4-mapped IPv6 form),
     *  - IPv6 fe80::/10 link-local,
     *  - the AWS IPv6 metadata endpoint fd00:ec2::254 (a specific address in
     *    ULA space, so the /128 is blocked without blocking all of fc00::/7).
     *
     * @param string $ip IP literal.
     *
     * @return bool
     */
    private static function is_blocked_ip( string $ip ): bool {
        $packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed literal is treated as not blocked.
        if ( false === $packed ) {
            return false;
        }

        $len = strlen( $packed );
        if ( 4 === $len ) {
            return 169 === ord( $packed[0] ) && 254 === ord( $packed[1] );
        }

        if ( 16 === $len ) {
            // IPv6 link-local fe80::/10: first byte 0xFE, top two bits of the second byte are 10.
            if ( 0xFE === ord( $packed[0] ) && 0x80 === ( ord( $packed[1] ) & 0xC0 ) ) {
                return true;
            }
            // AWS IPv6 instance-metadata endpoint fd00:ec2::254.
            if ( inet_pton( 'fd00:ec2::254' ) === $packed ) {
                return true;
            }
            // IPv4-mapped IPv6 ::ffff:169.254.x.x.
            if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF" === substr( $packed, 0, 12 ) ) {
                return 169 === ord( $packed[12] ) && 254 === ord( $packed[13] );
            }
        }

        return false;
    }
}
