<?php
/**
 * Remote MCP token generation and keyed hashing.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Opaque credentials and their stored HMAC.
 */
class OAuth_Token_Hasher {

    const TYPE_ACCESS  = 'access';
    const TYPE_REFRESH = 'refresh';
    const TYPE_CODE    = 'code';

    /**
     * Connection token minted in the panel. Stored in tokens.token_type, varchar(8).
     *
     * @var string
     */
    const TYPE_CONNECT = 'connect';

    /**
     * Approval handle. Never stored in the tokens table.
     *
     * @var string
     */
    const TYPE_APPROVAL = 'approval';

    /**
     * Prefixes let secret scanners and logs recognize leaked values.
     *
     * @var array
     */
    const PREFIXES = array(
        self::TYPE_ACCESS   => 'mwpat_',
        self::TYPE_REFRESH  => 'mwprt_',
        self::TYPE_CODE     => 'mwpac_',
        self::TYPE_CONNECT  => 'mwpct_',
        self::TYPE_APPROVAL => 'mwpap_',
    );

    /**
     * New credential: type prefix plus 43 base64url characters of 32 random bytes.
     *
     * @param string $type One of the TYPE_* constants.
     * @return string
     * @throws \InvalidArgumentException On an unknown type.
     */
    public function generate( string $type ): string {
        if ( ! isset( self::PREFIXES[ $type ] ) ) {
            throw new \InvalidArgumentException( 'Unknown credential type.' );
        }
        return self::PREFIXES[ $type ] . self::base64url( random_bytes( 32 ) );
    }

    /**
     * Whether a presented string has the shape of the expected credential type. Checked before
     * hashing, so a credential of another role is refused without a lookup.
     *
     * @param string $token Presented credential.
     * @param string $type  Expected type.
     * @return bool
     */
    public function matches_type( string $token, string $type ): bool {
        return isset( self::PREFIXES[ $type ] )
            && 1 === preg_match( '/^' . self::PREFIXES[ $type ] . '[A-Za-z0-9_-]{43}$/D', $token );
    }

    /**
     * Stored hash of a credential, keyed by the secret and the canonical resource, so a token minted
     * under one resource is never found under another, even after a search-replace of the columns.
     *
     * @param string $token              Credential.
     * @param string $canonical_resource Canonical MCP resource URL.
     * @return string 64 hex characters.
     */
    public function hash( string $token, string $canonical_resource ): string {
        $secret = $this->secret();
        $key    = hash_hmac( 'sha256', 'mainwp-remote-mcp-token-v1|' . $canonical_resource, $secret['key'] );
        return hash_hmac( 'sha256', $token, $key );
    }

    /**
     * Keyed digest under the module secret, separated by purpose (the approval's `args_digest` and
     * `plan_digest`). Keyed so a digest of credential-bearing arguments is no offline guessing oracle.
     *
     * @param string $purpose Short label, for example `args` or `plan`.
     * @param string $data    Canonical input.
     * @return string 64 hex characters.
     */
    public function digest( string $purpose, string $data ): string {
        return hash_hmac( 'sha256', $data, $this->derived_key( 'digest|' . $purpose ) );
    }

    /**
     * Baseline digest of a preview: the one function the gate uses when the
     * approved preview runs and when a native preview is re-run, so the two digests always compare
     * the same bytes. It covers only the contract's `compare_fields`, because some previews carry a
     * random fingerprint or a fresh native token that must never stale an approval. A named field
     * the preview lacks counts as null, so a field that disappears moves the baseline unless it
     * was null.
     *
     * The whole preview must have a JSON form, not only the named fields: a preview holding a value
     * JSON can't carry is not one the gate can reason about. A non-empty list is not an object and
     * has no named fields; an empty array is read as the PHP form of `{}`.
     *
     * @param mixed $preview        Preview output: an associative array or stdClass.
     * @param array $compare_fields Top-level field names, non-empty strings.
     * @return string|null 64 hex characters, or null when there is no baseline.
     */
    public function preview_baseline( $preview, array $compare_fields ): ?string {
        if ( $preview instanceof \stdClass ) {
            $values = get_object_vars( $preview );
        } elseif ( is_array( $preview ) && ( array() === $preview || ! array_is_list( $preview ) ) ) {
            $values = $preview;
        } else {
            return null;
        }
        if ( null === Call_Plan::canonical_json( $preview ) ) {
            return null;
        }
        $picked = new \stdClass();
        foreach ( $compare_fields as $field ) {
            if ( ! is_string( $field ) || '' === $field ) {
                return null;
            }
            $picked->{$field} = array_key_exists( $field, $values ) ? $values[ $field ] : null;
        }
        $json = Call_Plan::canonical_json( $picked );
        return null === $json ? null : $this->digest( 'preview_baseline', $json );
    }

    /**
     * Encrypt a value for storage, such as a call plan, with a key derived from the module secret.
     * The secret version is part of the result, so a rotated secret makes the value
     * unreadable rather than decryptable under the wrong key.
     *
     * @param string $purpose   Short label, for example `plan`.
     * @param string $plaintext Value.
     * @return string|null Sealed value, or null when it can't be encrypted.
     */
    public function seal( string $purpose, string $plaintext ): ?string {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            return null;
        }
        try {
            $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->derived_key( 'seal|' . $purpose, true ) );
        } catch ( \Throwable $e ) {
            return null;
        }
        return 'v' . $this->secret_version() . ':' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
    }

    /**
     * Decrypt a value from seal().
     *
     * @param string $purpose Label it was sealed under.
     * @param string $sealed  Sealed value.
     * @return string|null Plaintext, or null when it was sealed under another secret, purpose or is damaged.
     */
    public function open( string $purpose, string $sealed ): ?string {
        if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || 1 !== preg_match( '/^v(\d+):([A-Za-z0-9+\/=]+)$/D', $sealed, $parts ) ) {
            return null;
        }
        if ( (int) $parts[1] !== $this->secret_version() ) {
            return null;
        }
        $raw = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see seal().
        if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->derived_key( 'seal|' . $purpose, true ) );
        } catch ( \Throwable $e ) {
            return null;
        }
        return false === $plain ? null : $plain;
    }

    /**
     * Subkey of the module secret for one use, so a digest key never doubles as an encryption key.
     *
     * @param string $label  Use.
     * @param bool   $binary Raw 32 bytes instead of hex.
     * @return string
     */
    private function derived_key( string $label, bool $binary = false ): string {
        return hash_hmac( 'sha256', 'mainwp-remote-mcp-' . $label . '-v1', $this->secret()['key'], $binary );
    }

    /**
     * Version of the current secret.
     *
     * @return int
     */
    public function secret_version(): int {
        return $this->secret()['version'];
    }

    /**
     * Replace the secret with a new one at version + 1. Hashes under the old key can't be checked
     * afterwards, which is the point: callers revoke every grant alongside (OAuth_Store::rotate_secret()).
     *
     * @return int New version.
     * @throws \RuntimeException When the new secret could not be stored.
     */
    public function rotate_secret(): int {
        $version = $this->secret()['version'] + 1;
        // A fresh random key always differs from the stored one, so false here is a failed write, not "unchanged".
        if ( ! Remote_MCP_Settings::replace_secret( $version, random_bytes( 32 ) ) ) {
            throw new \RuntimeException( 'The new remote MCP token secret could not be stored.' );
        }
        return $version;
    }

    /**
     * Current secret, created on first use.
     *
     * @return array
     * @throws \RuntimeException When no secret can be stored.
     */
    private function secret(): array {
        $secret = Remote_MCP_Settings::get_secret();
        if ( null === $secret ) {
            // The insert keeps the first writer's row, and the re-read returns whichever secret won,
            // so a concurrent first use hashes with the same key.
            Remote_MCP_Settings::add_secret( 1, random_bytes( 32 ) );
            $secret = Remote_MCP_Settings::get_secret();
        }
        if ( null === $secret ) {
            throw new \RuntimeException( 'The remote MCP token secret is unavailable.' );
        }
        return $secret;
    }

    /**
     * Unpadded base64url.
     *
     * @param string $bytes Raw bytes.
     * @return string
     */
    public static function base64url( string $bytes ): string {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of random bytes and digests (RFC 7636).
    }
}
