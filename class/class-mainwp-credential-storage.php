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
     *   - the input unchanged when input is null (not provided) or not a string
     *   - the input unchanged when input is '' (credential cleared)
     *   - the input unchanged when input is already an envelope (idempotent)
     *   - the old envelope when the plaintext matches its decrypted value
     *     (unchanged save; no re-encryption, no key file rotation)
     *   - false when the encryption layer fails to produce an envelope
     *
     * Callers MUST treat a `false` return as a hard failure and refuse
     * to persist; otherwise the column silently downgrades to plaintext.
     *
     * The one-shot path never deletes the old envelope's key file because
     * the caller's storage write can still fail. Replacing a stored
     * credential should use stage_credential_encryption(), persist its
     * value, then commit_credential_intent() after a successful write or
     * rollback_credential_intent() after a failed write.
     *
     * @param mixed  $plaintext    Credential to encrypt.
     * @param string $field        Field name (used in keyfile prefix).
     * @param mixed  $old_envelope Previously stored value being replaced.
     * @return string|false|mixed
     */
    public static function encrypt_credential( $plaintext, $field = 'http_pass', $old_envelope = null ) {
        $intent = static::stage_credential_encryption( $plaintext, $field, $old_envelope );
        if ( false === $intent ) {
            return false;
        }
        $intent['retire_key'] = null;
        static::commit_credential_intent( $intent );
        return $intent['value'];
    }

    /**
     * Stage a credential encryption without touching any key file.
     *
     * Same value semantics as encrypt_credential(), but no key file is
     * deleted here: the caller persists the returned value first, then
     * settles the key files by outcome - commit_credential_intent()
     * after a confirmed write retires the superseded key, and
     * rollback_credential_intent() after a failed write deletes the key
     * this staging minted. Retiring before persistence loses the stored
     * credential for good when the write fails: the row keeps the old
     * envelope while its key file is already gone.
     *
     * Returns false when the encryption layer fails, otherwise:
     *   - value:      what to persist (same shapes encrypt_credential returns)
     *   - retire_key: superseded key file to delete once the write commits
     *   - fresh_key:  newly minted key file to delete if the write rolls back
     *
     * A staging that reuses the old envelope (unchanged value, idempotent
     * input, null/non-string passthrough) carries neither key.
     *
     * @param mixed  $plaintext    Credential to encrypt.
     * @param string $field        Field name (used in keyfile prefix).
     * @param mixed  $old_envelope Previously stored value being replaced.
     * @return array|false Intent array, or false on encryption failure.
     */
    public static function stage_credential_encryption( $plaintext, $field = 'http_pass', $old_envelope = null ) {
        $intent = array(
            'value'      => $plaintext,
            'retire_key' => null,
            'fresh_key'  => null,
        );
        if ( null === $plaintext || ! is_string( $plaintext ) ) {
            return $intent;
        }
        if ( '' === $plaintext ) {
            // A clear: nothing references the old envelope once the write
            // lands, so its key file retires on commit.
            $intent['retire_key'] = static::envelope_file_key( $old_envelope );
            return $intent;
        }
        if ( static::is_credential_envelope( $plaintext ) ) {
            return $intent;
        }
        if ( static::is_credential_envelope( $old_envelope )
            && static::decrypt_credential( $old_envelope ) === $plaintext ) {
            // Unchanged value: reuse the stored envelope instead of
            // producing a new key file and retiring the old one. The
            // Edit Site form posts http_user back as plaintext on every
            // save, so without this every save rotated the key file.
            // An unreadable old envelope decrypts to '' and never
            // matches a non-empty plaintext, so it is re-encrypted.
            $intent['value'] = $old_envelope;
            return $intent;
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
        $new_key         = (string) $envelope['file_key'];
        $old_key         = static::envelope_file_key( $old_envelope );
        $intent['value'] = self::ENVELOPE_PREFIX . $encoded;
        if ( $old_key === $new_key ) {
            // The encryption layer reused the stored key file: deleting it
            // on either outcome would orphan a live envelope.
            return $intent;
        }
        $intent['retire_key'] = $old_key;
        $intent['fresh_key']  = $new_key;
        return $intent;
    }

    /**
     * Settle a staged encryption after the write landed: retire the
     * superseded key file. Best-effort, like the pre-staging behavior.
     *
     * @param mixed $intent Intent from stage_credential_encryption().
     * @return void
     */
    public static function commit_credential_intent( $intent ) {
        if ( is_array( $intent ) && ! empty( $intent['retire_key'] ) && is_string( $intent['retire_key'] ) ) {
            do_action( 'mainwp_delete_key_file', $intent['retire_key'] );
        }
    }

    /**
     * Settle a staged encryption after the write failed: delete the key
     * file the staging minted, keeping the stored envelope's key intact.
     *
     * @param mixed $intent Intent from stage_credential_encryption().
     * @return void
     */
    public static function rollback_credential_intent( $intent ) {
        if ( is_array( $intent ) && ! empty( $intent['fresh_key'] ) && is_string( $intent['fresh_key'] ) ) {
            do_action( 'mainwp_delete_key_file', $intent['fresh_key'] );
        }
    }

    /**
     * The key file id an envelope references, or null for anything that
     * is not a well-formed envelope (legacy plaintext, null, garbage).
     *
     * @param mixed $stored Value as read from storage.
     * @return string|null
     */
    protected static function envelope_file_key( $stored ) {
        if ( ! static::is_credential_envelope( $stored ) ) {
            return null;
        }
        $data = json_decode( substr( $stored, strlen( self::ENVELOPE_PREFIX ) ), true );
        if ( ! is_array( $data ) || empty( $data['file_key'] ) || ! is_string( $data['file_key'] ) ) {
            return null;
        }
        return $data['file_key'];
    }

    /**
     * Fire mainwp_delete_key_file when a stored key file id is replaced.
     *
     * For storage shapes that hold the {encrypted_val, file_key} envelope
     * as a raw array (extension license options) rather than the prefixed
     * string this class produces. Best-effort: nothing happens without a
     * prior key, and a reused key is never deleted. A replacement with no
     * new key (the credential was cleared) still retires the old file -
     * nothing references it after the write.
     *
     * @param mixed $old_file_key Key file id the stored value referenced.
     * @param mixed $new_file_key Key file id the replacement uses, '' when cleared.
     * @return void
     */
    public static function retire_key_file_if_changed( $old_file_key, $new_file_key ) {
        if ( ! is_string( $old_file_key ) || '' === $old_file_key ) {
            return;
        }
        if ( is_string( $new_file_key ) && $old_file_key === $new_file_key ) {
            return;
        }
        do_action( 'mainwp_delete_key_file', $old_file_key );
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
