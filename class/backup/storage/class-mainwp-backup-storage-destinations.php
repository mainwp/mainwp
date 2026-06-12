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
        $encrypted = $this->encrypt_settings( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array() );
        if ( false === $encrypted ) {
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
                'settings'   => $encrypted,
                'is_default' => $is_default,
                'created'    => time(),
                'updated'    => time(),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%d' )
        );

        return $inserted ? (int) $this->wpdb->insert_id : 0;
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

        $old_file_key = '';
        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
            $encrypted = $this->encrypt_settings( $data['settings'] );
            if ( false === $encrypted ) {
                return false;
            }
            $row['settings'] = $encrypted;
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

        if ( false !== $updated && '' !== $old_file_key && isset( $row['settings'] ) && $old_file_key !== $this->envelope_file_key( (string) $row['settings'] ) ) {
            // The re-encrypted settings sealed under a fresh key file;
            // retire the superseded one so pk/ never accumulates key
            // material for ciphertext that no longer exists.
            MainWP_Keys_Manager::instance()->delete_key_file( $old_file_key );
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

        switch ( (string) $row->provider ) {
            case 'local':
                return new MainWP_Backup_Storage_Local( $settings );
            case 's3':
                return new MainWP_Backup_Storage_S3( $settings );
            case 'gcs':
                return new MainWP_Backup_Storage_GCS( $settings );
            case 'dropbox':
                return new MainWP_Backup_Storage_Dropbox( $settings );
            default:
                throw new \RuntimeException( esc_html( 'Unknown storage provider: ' . $row->provider ), 0 );
        }
    }

    /**
     * Encrypt a settings array for storage.
     *
     * @param array $settings Provider settings.
     *
     * @return string|false Envelope, or false when encryption is unavailable.
     */
    private function encrypt_settings( array $settings ) {
        $envelope = MainWP_Credential_Storage::encrypt_credential( (string) wp_json_encode( $settings ), 'storage_destination' );
        if ( ! is_string( $envelope ) || ! MainWP_Credential_Storage::is_credential_envelope( $envelope ) ) {
            return false;
        }
        return $envelope;
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
}
