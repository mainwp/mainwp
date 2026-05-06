<?php
/**
 * Credential storage helpers: at-rest encryption for site credentials.
 *
 * @package MainWP\Dashboard
 * @since   6.0.12
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Credential_Storage
 *
 * MWP-1548: encrypt site http_user / http_pass at rest using the same
 * mainwp_encrypt_key_value / mainwp_decrypt_key_value filter pair that
 * 3rd-party API keys (MWP-1546, MWP-1543) use. The wp_mainwp_wp.http_pass
 * column stored these credentials in plaintext for years; a DB-only
 * compromise (backup, dump, log leak) exposed every child site's HTTP
 * Basic Auth password.
 *
 * Storage shape: a serialized envelope `{encrypted_val, file_key}` is
 * produced by MainWP_Keys_Manager and JSON-encoded with a fixed prefix
 * so detection in mixed plaintext/ciphertext storage is unambiguous.
 *
 *   __mwpenc__:{"encrypted_val":"...","file_key":"..."}
 *
 * Detection is exact (prefix-based, not content-based) so a real
 * password that happens to look like JSON cannot be misclassified.
 *
 * Legacy plaintext rows continue to work via decrypt_credential()'s
 * pass-through fallback. New writes always produce envelopes when the
 * encryption layer is healthy; failures are surfaced to the caller via
 * a false return so persistence can be refused (fail-closed write).
 */
class MainWP_Credential_Storage {

    /**
     * Sentinel prefix that marks a stored value as a credential envelope.
     *
     * The double-underscore + lowercase + colon shape is intentionally
     * unlikely to appear at the start of any real HTTP Basic Auth
     * password. Detection is prefix-exact so we never misclassify a
     * legitimate password that happens to contain JSON-shaped bytes.
     *
     * @var string
     */
    const ENVELOPE_PREFIX = '__mwpenc__:';

    /**
     * Encrypt a plaintext credential for at-rest storage.
     *
     * Returns:
     *   - the prefixed envelope string on success
     *   - the input unchanged when input is empty / null / not a string
     *   - the input unchanged when input is already an envelope (idempotent)
     *   - false when the encryption layer fails to produce an envelope
     *
     * Callers MUST treat a `false` return as a hard failure and refuse
     * to persist; otherwise the column silently downgrades to plaintext.
     *
     * @param mixed  $plaintext Credential to encrypt.
     * @param string $field     Field name (used in keyfile prefix).
     * @return string|false|mixed
     */
    public static function encrypt_credential( $plaintext, $field = 'http_pass' ) {
        if ( null === $plaintext || '' === $plaintext ) {
            return $plaintext;
        }
        if ( ! is_string( $plaintext ) ) {
            return $plaintext;
        }
        if ( static::is_credential_envelope( $plaintext ) ) {
            return $plaintext;
        }
        $prefix   = 'site_credential_' . sanitize_key( $field ) . '_';
        $envelope = apply_filters( 'mainwp_encrypt_key_value', false, $plaintext, $prefix, false );
        if ( ! is_array( $envelope )
            || empty( $envelope['encrypted_val'] )
            || empty( $envelope['file_key'] ) ) {
            return false;
        }
        $encoded = wp_json_encode( $envelope );
        if ( ! is_string( $encoded ) || '' === $encoded ) {
            return false;
        }
        return self::ENVELOPE_PREFIX . $encoded;
    }

    /**
     * Decrypt a stored credential.
     *
     * Returns:
     *   - plaintext when input is an envelope and decryption succeeds
     *   - the input unchanged when input is legacy plaintext
     *   - empty string when input is an envelope but decryption fails
     *     (drop ciphertext rather than surfacing an unreadable blob to
     *     callers who would forward it as a real Basic Auth password)
     *
     * @param mixed $stored Value as read from storage.
     * @return string|mixed
     */
    public static function decrypt_credential( $stored ) {
        if ( null === $stored || '' === $stored ) {
            return $stored;
        }
        if ( ! is_string( $stored ) ) {
            return $stored;
        }
        if ( ! static::is_credential_envelope( $stored ) ) {
            return $stored;
        }
        $json     = substr( $stored, strlen( self::ENVELOPE_PREFIX ) );
        $envelope = json_decode( $json, true );
        if ( ! is_array( $envelope )
            || empty( $envelope['encrypted_val'] )
            || empty( $envelope['file_key'] ) ) {
            return '';
        }
        $decrypted = apply_filters( 'mainwp_decrypt_key_value', false, $envelope, '' );
        if ( is_string( $decrypted ) && '' !== $decrypted ) {
            return $decrypted;
        }
        return '';
    }

    /**
     * Is $stored a credential envelope produced by encrypt_credential()?
     *
     * Detection is by prefix only, so it cannot misclassify a real
     * password that happens to be JSON-shaped.
     *
     * @param mixed $stored Value as read from storage.
     * @return bool
     */
    public static function is_credential_envelope( $stored ) {
        if ( ! is_string( $stored ) ) {
            return false;
        }
        $prefix_len = strlen( self::ENVELOPE_PREFIX );
        if ( strlen( $stored ) <= $prefix_len ) {
            return false;
        }
        return 0 === strncmp( $stored, self::ENVELOPE_PREFIX, $prefix_len );
    }
}
