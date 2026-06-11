<?php
/**
 * MainWP Backup Storage Crypto - LEGACY decoder.
 *
 * The original at-rest encryption for cloud storage destination settings:
 * PHP's native openssl AES-256-GCM with a key derived from AUTH_KEY
 * through HKDF. Destination settings now encrypt through
 * MainWP_Credential_Storage instead - the split-key envelope system
 * (MWP-1548) every other MainWP secret uses, whose key material lives in
 * the private pk/ directory rather than wp-config.php and survives salt
 * rotation. This class remains only to read rows written before that
 * convergence: decode_row() falls back to it and
 * migrate_legacy_settings() re-encrypts through it once. No new writes
 * may use this format.
 *
 * Every operation fails closed: any tampering, truncation, or key change
 * yields false rather than partial or garbage plaintext, which callers
 * surface as a re-enter-credentials state.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || die();

/**
 * Class MainWP_Backup_Storage_Crypto
 */
class MainWP_Backup_Storage_Crypto { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Prefix marking a value as an encrypted storage-settings envelope.
     *
     * @var string
     */
    const ENVELOPE_PREFIX = 'mwpenc-gcm1:';

    /**
     * HKDF context string binding derived keys to this subsystem.
     *
     * @var string
     */
    const HKDF_CONTEXT = 'mainwp-backup-storage-v1';

    /**
     * GCM IV length in bytes.
     *
     * @var int
     */
    const IV_LENGTH = 12;

    /**
     * GCM auth tag length in bytes.
     *
     * @var int
     */
    const TAG_LENGTH = 16;

    /**
     * Encrypt a settings payload.
     *
     * @param string      $plaintext    The settings JSON to encrypt.
     * @param string|null $key_material Override key material (tests); defaults to AUTH_KEY.
     *
     * @return string|false Envelope string, or false on any failure.
     */
    public static function encrypt( $plaintext, $key_material = null ) {
        if ( ! is_string( $plaintext ) ) {
            return false;
        }

        $key = static::derive_key( $key_material );
        if ( false === $key ) {
            return false;
        }

        try {
            $iv = random_bytes( static::IV_LENGTH );
        } catch ( \Exception ) {
            return false;
        }

        $tag        = '';
        $ciphertext = \openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', static::TAG_LENGTH );

        if ( false === $ciphertext || static::TAG_LENGTH !== strlen( $tag ) ) {
            return false;
        }

        return static::ENVELOPE_PREFIX . base64_encode( $iv . $ciphertext . $tag ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary envelope encoding, not obfuscation.
    }

    /**
     * Decrypt a settings envelope.
     *
     * @param string      $stored       The stored envelope value.
     * @param string|null $key_material Override key material (tests); defaults to AUTH_KEY.
     *
     * @return string|false Plaintext, or false on any failure (fail closed).
     */
    public static function decrypt( $stored, $key_material = null ) {
        if ( ! static::is_encrypted( $stored ) ) {
            return false;
        }

        $key = static::derive_key( $key_material );
        if ( false === $key ) {
            return false;
        }

        $raw = base64_decode( substr( $stored, strlen( static::ENVELOPE_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope decoding.
        if ( false === $raw || strlen( $raw ) < static::IV_LENGTH + static::TAG_LENGTH ) {
            return false;
        }

        $iv         = substr( $raw, 0, static::IV_LENGTH );
        $tag        = substr( $raw, - static::TAG_LENGTH );
        $ciphertext = substr( $raw, static::IV_LENGTH, strlen( $raw ) - static::IV_LENGTH - static::TAG_LENGTH );

        $plaintext = \openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

        return is_string( $plaintext ) ? $plaintext : false;
    }

    /**
     * Whether a value is an encrypted storage-settings envelope.
     *
     * @param mixed $value The stored value.
     *
     * @return bool
     */
    public static function is_encrypted( $value ) {
        return is_string( $value ) && 0 === strncmp( $value, static::ENVELOPE_PREFIX, strlen( static::ENVELOPE_PREFIX ) );
    }

    /**
     * Derive the 32-byte cipher key from the key material.
     *
     * @param string|null $key_material Override key material; defaults to AUTH_KEY.
     *
     * @return string|false 32 raw bytes, or false when no usable key exists (fail closed).
     */
    private static function derive_key( $key_material = null ) {
        if ( null === $key_material ) {
            $key_material = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        }

        if ( ! is_string( $key_material ) || '' === $key_material || ! function_exists( '\openssl_encrypt' ) || ! function_exists( '\hash_hkdf' ) ) {
            return false;
        }

        return hash_hkdf( 'sha256', $key_material, 32, static::HKDF_CONTEXT );
    }
}
