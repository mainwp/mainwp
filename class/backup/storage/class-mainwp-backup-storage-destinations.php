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
     * Decrypted rows for this request only. Callers receive clones.
     *
     * @var array<int, object|null>
     */
    private $destination_rows = array();

    /**
     * Decoded settings for identical ciphertext in this request.
     *
     * @var array<string, array>
     */
    private $decoded_settings = array();

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
        $this->destination_rows = array();
        $this->decoded_settings = array();

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

        $this->destination_rows = array();
        $this->decoded_settings = array();

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
     * A successful provider or settings update clears SFTP trust when entering
     * or leaving SFTP, or when readable SFTP settings identify a different server.
     * Unreadable SFTP-to-SFTP recovery preserves any surviving pin.
     *
     * @param int         $id   Destination id.
     * @param array       $data Fields to update: provider, name, settings, is_default.
     * @param object|null $expected Optional source row for a conditional settings write.
     *
     * @return bool
     */
    public function update_destination( int $id, array $data, ?object $expected = null ): bool {
        $this->destination_rows = array();
        $this->decoded_settings = array();

        $row          = array();
        $format       = array();
        $where        = array( 'id' => $id );
        $where_format = array( '%d' );
        if ( null !== $expected ) {
            $raw     = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE id = %d', $id ) );
            $current = $this->decode_row( null === $raw ? null : clone $raw );
            if ( null === $current || $current->provider !== $expected->provider || $current->settings !== $expected->settings
                || $current->name !== $expected->name || (int) $current->is_default !== (int) $expected->is_default ) {
                return false;
            }
            $where        = array(
                'id'         => $id,
                'provider'   => $raw->provider,
                'settings'   => $raw->settings,
                'name'       => $raw->name,
                'is_default' => $raw->is_default,
            );
            $where_format = array( '%d', '%s', '%s', '%s', '%d' );
        }

        foreach ( array( 'provider', 'name' ) as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $row[ $field ] = (string) $data[ $field ];
                $format[]      = '%s';
            }
        }

        $old_file_key     = '';
        $settings_intent  = null;
        $reset_sftp_trust = false;
        if ( isset( $data['provider'] ) || isset( $data['settings'] ) ) {
            $existing = $this->get_destination( $id );
            if ( null !== $existing ) {
                $existing_settings = is_array( $existing->settings ) ? $existing->settings : array();
                $new_settings      = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : $existing_settings;
                $decision          = MainWP_Backup_Storage_Handler::sftp_pin_decision( $id, (string) $existing->provider, (string) ( $data['provider'] ?? $existing->provider ), $new_settings, $existing_settings );
                $reset_sftp_trust  = $decision['reset'];
            }
        }
        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
            $settings_intent = $this->stage_settings_encryption( $data['settings'] );
            if ( false === $settings_intent ) {
                return false;
            }
            $row['settings'] = $settings_intent['value'];
            $format[]        = '%s';

            $old_file_key = null === $expected ? $this->stored_file_key( $id ) : $this->envelope_file_key( (string) $raw->settings );
        }

        if ( isset( $data['is_default'] ) ) {
            $is_default = empty( $data['is_default'] ) ? 0 : 1;
            if ( $is_default && null === $expected ) {
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
            $where,
            $format,
            $where_format
        );

        $this->destination_rows = array();
        $this->decoded_settings = array();

        if ( null !== $expected && 0 === $updated ) {
            $updated = false;
        }
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

        if ( false !== $updated && $reset_sftp_trust ) {
            // A failed save must leave the original destination's trust intact.
            MainWP_Backup_Storage_SFTP::forget_host_key( $id );
        }
        if ( false !== $updated && null !== $expected && ! empty( $row['is_default'] ) ) {
            $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp_backup_storage' ) . ' SET is_default = 0 WHERE is_default = 1 AND id <> %d', $id ) );
            $this->destination_rows = array();
            $this->decoded_settings = array();
        }

        return false !== $updated;
    }

    /**
     * Establish Dropbox namespace proof before an opted-in upload writes archives.
     *
     * @param object $destination Source destination row.
     * @return object Reloaded destination with persisted proof.
     * @throws \RuntimeException When proof or a conditional write fails.
     */
    public function prepare_retention_identity( object $destination ): object {
        if ( 'dropbox' !== $destination->provider || MainWP_Backup_Retention_Protection::dropbox_identity_valid( $destination->settings ) ) {
            return $destination;
        }
        $field    = MainWP_Backup_Retention_Protection::DROPBOX_IDENTITY_FIELD;
        $settings = $destination->settings;
        if ( empty( $settings[ $field ]['key'] ) ) {
            $settings[ $field ] = array( 'key' => '.mainwp-retention-' . bin2hex( random_bytes( 16 ) ) );
            if ( ! $this->update_destination( (int) $destination->id, array( 'settings' => $settings ), $destination ) ) {
                throw new \RuntimeException( 'Storage destination changed while preparing its backup identity.' );
            }
            $destination = $this->get_destination( (int) $destination->id );
            if ( null === $destination || 'dropbox' !== $destination->provider || $destination->settings !== $settings ) {
                throw new \RuntimeException( 'Storage destination changed while preparing its backup identity.' );
            }
        }
        $provider = $this->make_provider( $destination );
        if ( ! $provider instanceof MainWP_Backup_Storage_Dropbox ) {
            throw new \RuntimeException( 'Dropbox storage identity could not be verified.' );
        }
        $settings = MainWP_Backup_Retention_Protection::with_dropbox_identity( $destination->settings, $provider->retention_marker_id( $destination->settings[ $field ]['key'] ) );
        if ( ! $this->update_destination( (int) $destination->id, array( 'settings' => $settings ), $destination ) ) {
            throw new \RuntimeException( 'Storage destination changed before its backup identity could be saved.' );
        }
        $current = $this->get_destination( (int) $destination->id );
        if ( null === $current || 'dropbox' !== $current->provider || ! MainWP_Backup_Retention_Protection::dropbox_identity_valid( $current->settings ) ) {
            throw new \RuntimeException( 'Storage destination identity could not be read back.' );
        }
        return $current;
    }

    /**
     * Delete a storage destination and retire its key file.
     *
     * @param int $id Destination id.
     *
     * @return bool
     */
    public function delete_destination( int $id ): bool {
        $this->destination_rows = array();
        $this->decoded_settings = array();

        $file_key = $this->stored_file_key( $id );

        $deleted = (bool) $this->wpdb->delete( $this->table_name( 'wp_backup_storage' ), array( 'id' => $id ), array( '%d' ) );

        $this->destination_rows = array();
        $this->decoded_settings = array();

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
     * @param int  $id Destination id.
     * @param bool $refresh Read the current row; false reuses readable rows for list previews.
     *
     * @return object|null
     */
    public function get_destination( int $id, bool $refresh = true ): ?object {
        if ( $refresh || ! array_key_exists( $id, $this->destination_rows ) || ! empty( $this->destination_rows[ $id ]->credentials_invalid ) ) {
            $row                           = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    'SELECT * FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE id = %d',
                    $id
                )
            );
            $this->destination_rows[ $id ] = $this->decode_row( $row );
        }
        return null === $this->destination_rows[ $id ] ? null : clone $this->destination_rows[ $id ];
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
        if ( null === $row ) {
            return null;
        }
        $id                            = (int) $row->id;
        $this->destination_rows[ $id ] = $this->decode_row( $row );
        return clone $this->destination_rows[ $id ];
    }

    /**
     * Routing needs the Default identity even when its credentials cannot load.
     *
     * @return int Destination id, or 0 when no Default is marked.
     */
    public function get_default_id(): int {
        return (int) $this->wpdb->get_var(
            'SELECT id FROM ' . $this->table_name( 'wp_backup_storage' ) . ' WHERE is_default = 1 ORDER BY id DESC LIMIT 1'
        );
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
        $this->destination_rows = array();
        $this->decoded_settings = array();
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

        $stored = (string) $row->settings;
        if ( ! array_key_exists( $stored, $this->decoded_settings ) ) {
            $plaintext = false;
            if ( MainWP_Credential_Storage::is_credential_envelope( $stored ) ) {
                $decrypted = MainWP_Credential_Storage::decrypt_credential( $stored );
                $plaintext = '' === $decrypted ? false : $decrypted;
            }
            $decoded = false === $plaintext ? null : json_decode( $plaintext, true );
            if ( is_array( $decoded ) ) {
                $this->decoded_settings[ $stored ] = $decoded;
            }
        }
        $row->settings            = $this->decoded_settings[ $stored ] ?? null;
        $row->credentials_invalid = null === $row->settings;

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
        $host = self::normalize_host( $host );
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

        // The transport decodes percent-escapes in the host; this check would classify the encoded form.
        if ( false !== strpos( $host, '%' ) ) {
            return false;
        }

        // Transports accept numeric IPv4 spellings that DNS may fail to classify.
        if ( self::is_ip_like( $host ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false;
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
     * Whether a host is an IP literal, including the IPv4 spellings
     * FILTER_VALIDATE_IP rejects but inet_aton-style resolvers accept
     * (10.1.2, 012.01.02.03, 0xa.1.2.3, 167837698).
     *
     * @param string $host Normalized host.
     *
     * @return bool
     */
    public static function is_ip_like( string $host ): bool {
        return false !== filter_var( $host, FILTER_VALIDATE_IP ) || 1 === preg_match( '/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*$/i', $host );
    }

    /**
     * Reduce a host to the form the checks classify: wp_parse_url() leaves
     * brackets on IPv6 literals, and a trailing dot or an IPv4-mapped IPv6
     * spelling must not let a metadata address skip the IP-literal branch.
     *
     * @param string $host Hostname or IP literal as received.
     *
     * @return string
     */
    private static function normalize_host( string $host ): string {
        $host = rtrim( trim( trim( $host ), '[]' ), '.' );

        $packed = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? @inet_pton( $host ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validated just above.
        if ( is_string( $packed ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF" === substr( $packed, 0, 12 ) ) {
            return (string) inet_ntop( substr( $packed, 12 ) );
        }

        return $host;
    }

    /**
     * Whether plain HTTP to a storage host stays on this machine or the
     * private network: every address must be loopback, RFC1918, CGNAT or
     * IPv6 ULA, and none may be an address is_blocked_ip() refuses.
     * Self-hosted MinIO, LAN NAS and Tailscale endpoints are often HTTP-only,
     * while a public http:// endpoint would carry signed requests and backup
     * bodies in cleartext. Unlike is_safe_host() this fails closed, so a host
     * that does not resolve, or has any public answer, is not private.
     *
     * @param string $host Hostname or IP literal; IPv6 may be bracketed as wp_parse_url returns it.
     *
     * @return bool
     */
    public static function is_private_network_host( string $host ): bool {
        $host = self::normalize_host( $host );
        if ( '' === $host ) {
            return false;
        }

        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve_host_ips( $host );
        if ( empty( $ips ) ) {
            return false;
        }

        foreach ( $ips as $ip ) {
            if ( self::is_blocked_ip( (string) $ip ) || ! self::is_private_ip( (string) $ip ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a host is an IP literal or resolves to at least one address,
     * so a refusal can tell an unreachable name from a public one.
     *
     * @param string $host Hostname or IP literal.
     *
     * @return bool
     */
    public static function host_resolves( string $host ): bool {
        $host = self::normalize_host( $host );
        if ( '' === $host ) {
            return false;
        }
        return filter_var( $host, FILTER_VALIDATE_IP ) || ! empty( self::resolve_host_ips( $host ) );
    }

    /**
     * Whether an IP is loopback (127.0.0.0/8, ::1), RFC1918 (10/8,
     * 172.16/12, 192.168/16), CGNAT 100.64.0.0/10 (Tailscale and similar
     * VPNs) or IPv6 ULA (fc00::/7), including the IPv4-mapped IPv6 form of
     * the IPv4 ranges.
     *
     * @param string $ip IP literal.
     *
     * @return bool
     */
    private static function is_private_ip( string $ip ): bool {
        $packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed literal is treated as not private.
        if ( false === $packed ) {
            return false;
        }

        if ( 16 === strlen( $packed ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF" === substr( $packed, 0, 12 ) ) {
            $packed = substr( $packed, 12 );
        }

        if ( 4 === strlen( $packed ) ) {
            $first  = ord( $packed[0] );
            $second = ord( $packed[1] );
            return 127 === $first
                || 10 === $first
                || ( 172 === $first && $second >= 16 && $second <= 31 )
                || ( 192 === $first && 168 === $second )
                || ( 100 === $first && $second >= 64 && $second <= 127 );
        }

        return inet_pton( '::1' ) === $packed || 0xFC === ( ord( $packed[0] ) & 0xFE );
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
