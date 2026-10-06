<?php
/**
 * MainWP Backup Encryption.
 *
 * The key hierarchy behind archive encryption. One stable per-dashboard
 * key-encryption key (KEK) lives in a pk/ key file. Every snapshot gets its
 * own random data-encryption key (DEK) that seals that snapshot's archive
 * bytes; the DEK is stored wrapped under the KEK. The operator sets a
 * recovery passphrase whose PBKDF2-derived key also wraps the KEK, so a
 * dashboard that has lost its pk/ key file can rebuild the KEK from the
 * passphrase and still read its offsite backups.
 *
 * The KEK is created once and never regenerated. Changing the passphrase
 * re-wraps the same KEK under the new passphrase; it does not mint a new
 * KEK, because every wrapped DEK already in the catalog is sealed under the
 * current KEK and would be orphaned by a new one.
 *
 * Key-wrapping reuses the archive cipher primitive (AES-256-GCM); only
 * small fixed-size secrets pass through it here, so there is no streaming
 * concern. The pk/ key-file storage is reused from MainWP_Keys_Manager.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || die();

/**
 * Class MainWP_Backup_Encryption
 */
class MainWP_Backup_Encryption { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Option holding the encryption on/off toggle ('1' when on).
     *
     * @var string
     */
    const ENABLED_OPTION = 'mainwp_backup_encryption_enabled';

    /**
     * Option holding the self-describing passphrase-wrapped-KEK recovery blob.
     *
     * @var string
     */
    const RECOVERY_OPTION = 'mainwp_backup_recovery_kek';

    /**
     * Short recovery passphrases leave the exported key vulnerable to guessing.
     *
     * @var int
     */
    const MIN_PASSPHRASE_LENGTH = 12;

    /**
     * PBKDF2 hash algorithm for passphrase derivation.
     *
     * @var string
     */
    const PBKDF2_ALGO = 'sha256';

    /**
     * PBKDF2 iteration count (OWASP 2023 floor for PBKDF2-HMAC-SHA256).
     *
     * @var int
     */
    const PBKDF2_ITERATIONS = 600000;

    /**
     * Cipher identifier recorded with each snapshot's encryption metadata.
     *
     * @var string
     */
    const CIPHER_ID = 'aes-256-gcm';

    /**
     * The pk/ key-file identifier for the dashboard KEK.
     *
     * @var string
     */
    const KEK_FILE_KEY = 'backup_master';

    /**
     * The pk/ key-file purpose (domain separator) for the dashboard KEK.
     *
     * @var string
     */
    const KEK_FILE_PURPOSE = 'backup_kek';

    /**
     * Single instance.
     *
     * @var MainWP_Backup_Encryption|null
     */
    private static $instance = null;

    /**
     * Return the singleton.
     *
     * @return MainWP_Backup_Encryption
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * The opaque pk/ filename the KEK is stored under.
     *
     * @return string
     */
    public function kek_filename() {
        return MainWP_System_Utility::get_private_filename( 'pk', static::KEK_FILE_KEY, static::KEK_FILE_PURPOSE );
    }

    /**
     * Whether the operator turned encryption on.
     *
     * @return bool
     */
    public function is_enabled_setting() {
        return '1' === (string) get_option( static::ENABLED_OPTION, '' );
    }

    /**
     * Whether encryption is fully armed: toggled on, a KEK exists, and a
     * recovery blob exists. Backups only seal when this is true, so the
     * toggle can never produce encrypted-without-recovery archives.
     *
     * @return bool
     */
    public function is_armed() {
        if ( ! $this->is_enabled_setting() ) {
            return false;
        }
        if ( null === $this->load_kek() ) {
            return false;
        }
        $recovery = get_option( static::RECOVERY_OPTION );
        return is_array( $recovery ) && ! empty( $recovery['wrapped_kek'] );
    }

    /**
     * Load the KEK from its key file, or null when it is absent or invalid.
     *
     * @return string|null
     */
    public function load_kek() {
        $value = MainWP_Keys_Manager::instance()->get_key_val( $this->kek_filename() );
        return ( is_string( $value ) && MainWP_Backup_Archive_Cipher::KEY_LEN === strlen( $value ) ) ? $value : null;
    }

    /**
     * Load the KEK, creating and persisting one on first use. Stable across
     * calls and across passphrase changes.
     *
     * @return string
     *
     * @throws \RuntimeException When a new KEK cannot be persisted to its key file.
     */
    public function get_or_create_kek() {
        $kek = $this->load_kek();
        if ( null !== $kek ) {
            return $kek;
        }
        $kek = random_bytes( MainWP_Backup_Archive_Cipher::KEY_LEN );
        if ( ! MainWP_Keys_Manager::instance()->save_key_file( $this->kek_filename(), $kek ) ) {
            // Fail loud: returning an unpersisted KEK would let a backup seal
            // DEKs under a key the next call cannot reload, orphaning them.
            throw new \RuntimeException( esc_html( 'Could not persist the backup encryption key.' ) );
        }
        return $kek;
    }

    /**
     * A short, stable identifier for a KEK, embedded with wrapped material so
     * a future KEK rotation can select the right key to unwrap older data.
     *
     * @param string $kek The KEK bytes.
     *
     * @return string 16-hex-char version id.
     */
    public function kek_version_id( $kek ) {
        return substr( hash_hmac( 'sha256', 'kek_version', $kek ), 0, 16 );
    }

    /**
     * Generate a fresh per-snapshot data key.
     *
     * @return string 32 random bytes.
     */
    public function generate_dek() {
        return random_bytes( MainWP_Backup_Archive_Cipher::KEY_LEN );
    }

    /**
     * Wrap a DEK under the KEK, bound to its snapshot id.
     *
     * @param string $dek         The data key to wrap.
     * @param int    $snapshot_id The snapshot the DEK belongs to.
     * @param string $kek         The wrapping key.
     *
     * @return string base64 of the sealed DEK.
     */
    public function wrap_dek( $dek, $snapshot_id, $kek ) {
        return base64_encode( MainWP_Backup_Archive_Cipher::seal( $dek, $kek, $this->dek_aad( (int) $snapshot_id ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary key blob for storage.
    }

    /**
     * Unwrap a DEK wrapped by wrap_dek().
     *
     * @param string $wrapped     base64 of the sealed DEK.
     * @param int    $snapshot_id The snapshot the DEK belongs to.
     * @param string $kek         The wrapping key.
     *
     * @return string The recovered 32-byte DEK.
     *
     * @throws \RuntimeException When the wrapping is malformed or fails to open.
     */
    public function unwrap_dek( $wrapped, $snapshot_id, $kek ) {
        $blob = base64_decode( (string) $wrapped, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key blob from storage.
        if ( false === $blob ) {
            throw new \RuntimeException( esc_html( 'Wrapped data key is not valid base64.' ) );
        }
        return MainWP_Backup_Archive_Cipher::open( $blob, $kek, $this->dek_aad( (int) $snapshot_id ) );
    }

    /**
     * Set or change the recovery passphrase. Creates the KEK on first use,
     * restores it from the stored blob when the key file is lost, and always
     * re-wraps the same KEK; never regenerates it.
     *
     * @param string $passphrase The operator passphrase.
     *
     * @return array The self-describing recovery blob that was stored.
     * @throws \InvalidArgumentException When the trimmed passphrase is too short, or the key file is lost and the passphrase does not open the stored blob.
     * @throws \RuntimeException When a recovered or new KEK cannot be persisted to its key file.
     */
    public function set_recovery_passphrase( $passphrase ) {
        if ( mb_strlen( trim( (string) $passphrase ), 'UTF-8' ) < self::MIN_PASSPHRASE_LENGTH ) {
            throw new \InvalidArgumentException( esc_html__( 'The recovery passphrase must be at least 12 characters.', 'mainwp' ) );
        }
        $kek      = $this->load_kek();
        $existing = $this->get_recovery_blob();
        if ( null === $kek && ! empty( $existing['wrapped_kek'] ) ) {
            // With the key file lost, the stored blob holds the only copy of the
            // KEK every wrapped DEK depends on. A new KEK would overwrite it.
            try {
                $kek = $this->recover_kek( (string) $passphrase, $existing );
            } catch ( \Throwable $e ) {
                throw new \InvalidArgumentException( esc_html__( 'The passphrase does not open the stored recovery key, so the backup encryption key was not replaced.', 'mainwp' ) );
            }
            if ( ! MainWP_Keys_Manager::instance()->save_key_file( $this->kek_filename(), $kek ) ) {
                throw new \RuntimeException( esc_html( 'Could not persist the recovered backup encryption key.' ) );
            }
        }
        $kek          = null !== $kek ? $kek : $this->get_or_create_kek();
        $salt         = random_bytes( 16 );
        $recovery_key = hash_pbkdf2( static::PBKDF2_ALGO, (string) $passphrase, $salt, static::PBKDF2_ITERATIONS, MainWP_Backup_Archive_Cipher::KEY_LEN, true );
        $wrapped      = MainWP_Backup_Archive_Cipher::seal( $kek, $recovery_key, 'wrap:kek' );

        $blob = array(
            'algo'           => 'pbkdf2-' . static::PBKDF2_ALGO,
            'iters'          => static::PBKDF2_ITERATIONS,
            'salt'           => base64_encode( $salt ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary salt for storage.
            'wrapped_kek'    => base64_encode( $wrapped ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary key blob for storage.
            'kek_version_id' => $this->kek_version_id( $kek ),
        );

        update_option( static::RECOVERY_OPTION, $blob, false );
        return $blob;
    }

    /**
     * Rebuild the KEK from a recovery blob and the passphrase.
     *
     * @param string $passphrase The operator passphrase.
     * @param array  $blob        A recovery blob from set_recovery_passphrase().
     *
     * @return string The recovered KEK.
     *
     * @throws \RuntimeException When the blob is malformed or the passphrase is wrong.
     */
    public function recover_kek( $passphrase, $blob ) {
        if ( ! is_array( $blob ) || empty( $blob['salt'] ) || empty( $blob['wrapped_kek'] ) || empty( $blob['iters'] ) ) {
            throw new \RuntimeException( esc_html( 'Recovery material is incomplete.' ) );
        }
        $salt    = base64_decode( (string) $blob['salt'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary salt from storage.
        $wrapped = base64_decode( (string) $blob['wrapped_kek'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key blob from storage.
        if ( false === $salt || false === $wrapped ) {
            throw new \RuntimeException( esc_html( 'Recovery material is not valid base64.' ) );
        }
        $algo         = ( isset( $blob['algo'] ) && 'pbkdf2-' === substr( (string) $blob['algo'], 0, 7 ) ) ? substr( (string) $blob['algo'], 7 ) : static::PBKDF2_ALGO;
        $recovery_key = hash_pbkdf2( $algo, (string) $passphrase, $salt, (int) $blob['iters'], MainWP_Backup_Archive_Cipher::KEY_LEN, true );
        return MainWP_Backup_Archive_Cipher::open( $wrapped, $recovery_key, 'wrap:kek' );
    }

    /**
     * The stored recovery blob, or an empty array when none is set.
     *
     * @return array
     */
    public function get_recovery_blob() {
        $blob = get_option( static::RECOVERY_OPTION );
        return is_array( $blob ) ? $blob : array();
    }

    /**
     * Keyed integrity tag over an offsite manifest's exact serialized bytes.
     * Keyed by the KEK so only a holder of the KEK can have produced it,
     * which is what lets a recovering dashboard trust a manifest it reads
     * back from the bucket.
     *
     * @param string $manifest_bytes The exact manifest JSON byte string.
     * @param string $kek            The KEK.
     *
     * @return string Hex HMAC-SHA256.
     */
    public function manifest_hmac( $manifest_bytes, $kek ) {
        return hash_hmac( 'sha256', (string) $manifest_bytes, $kek );
    }

    /**
     * Build the per-snapshot encryption metadata recorded in the catalog and
     * echoed into the offsite manifest. Mints a fresh DEK, wraps it under the
     * current KEK, and returns the metadata block; the raw DEK is never
     * stored - it is unwrapped on demand from the wrapped form.
     *
     * @param int $snapshot_id The snapshot the DEK belongs to.
     *
     * @return array Encryption metadata: enc, cipher, overhead, wrapped_dek, kek_version_id.
     *
     * @throws \RuntimeException When the KEK cannot be loaded or created.
     */
    public function snapshot_encryption_meta( $snapshot_id ) {
        $kek = $this->get_or_create_kek();
        $dek = $this->generate_dek();
        return array(
            'enc'            => 1,
            'cipher'         => static::CIPHER_ID,
            'overhead'       => MainWP_Backup_Archive_Cipher::OVERHEAD,
            'wrapped_dek'    => $this->wrap_dek( $dek, (int) $snapshot_id, $kek ),
            'kek_version_id' => $this->kek_version_id( $kek ),
        );
    }

    /**
     * Recover the DEK from a snapshot's encryption metadata using a KEK.
     *
     * @param array  $meta        Encryption metadata from snapshot_encryption_meta().
     * @param int    $snapshot_id The snapshot the DEK belongs to.
     * @param string $kek         The wrapping key (from load_kek or recover_kek).
     *
     * @return string The 32-byte DEK.
     *
     * @throws \RuntimeException When the metadata has no wrapped DEK or it fails to open.
     */
    public function dek_from_meta( $meta, $snapshot_id, $kek ) {
        if ( ! is_array( $meta ) || empty( $meta['wrapped_dek'] ) ) {
            throw new \RuntimeException( esc_html( 'Snapshot encryption metadata has no wrapped data key.' ) );
        }
        return $this->unwrap_dek( (string) $meta['wrapped_dek'], (int) $snapshot_id, $kek );
    }

    /**
     * Whether a snapshot's decoded encryption metadata marks it as encrypted.
     *
     * @param mixed $meta Decoded encryption metadata (array) or anything else.
     *
     * @return bool
     */
    public static function meta_is_encrypted( $meta ) {
        return is_array( $meta ) && ! empty( $meta['enc'] );
    }

    /**
     * AAD that binds a wrapped DEK to its snapshot.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return string
     */
    private function dek_aad( $snapshot_id ) {
        return 'wrap:dek:' . $snapshot_id;
    }
}
