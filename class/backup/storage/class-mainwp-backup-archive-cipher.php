<?php
/**
 * MainWP Backup Archive Cipher.
 *
 * The per-unit authenticated cipher that seals backup archive bytes before
 * they leave the dashboard for offsite storage. Each unit (a whole small
 * artifact or one chunk of a large one) is sealed independently with
 * AES-256-GCM, so a multi-gigabyte archive never has to be held in memory
 * to be encrypted or decrypted - the upload driver seals one chunk at a
 * time and the restore driver opens one chunk at a time.
 *
 * Wire framing is the raw byte string nonce(12) . ciphertext . tag(16).
 * GCM ciphertext is the same length as its plaintext, so a sealed unit is
 * always exactly plaintext + 28 bytes. That fixed overhead lets the upload
 * driver predict the remote object size for its size-confirmation checks
 * without re-reading the bytes.
 *
 * The AAD is supplied by the caller and binds each unit to its snapshot,
 * artifact, and offset, so a unit sealed for one position cannot be opened
 * at another even when neighbouring units share the same data key.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

use phpseclib3\Crypt\AES;

defined( 'ABSPATH' ) || die();

/**
 * Class MainWP_Backup_Archive_Cipher
 */
class MainWP_Backup_Archive_Cipher { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * GCM nonce length in bytes (96-bit, the NIST-recommended GCM nonce).
     *
     * @var int
     */
    const NONCE_LEN = 12;

    /**
     * GCM authentication tag length in bytes.
     *
     * @var int
     */
    const TAG_LEN = 16;

    /**
     * Fixed per-unit framing overhead (nonce + tag) in bytes.
     *
     * @var int
     */
    const OVERHEAD = 28;

    /**
     * Required data-key length in bytes (AES-256).
     *
     * @var int
     */
    const KEY_LEN = 32;

    /**
     * The fixed framing overhead a seal adds to every unit.
     *
     * @return int
     */
    public static function overhead() {
        return static::OVERHEAD;
    }

    /**
     * Make sure the bundled phpseclib autoloader is available.
     *
     * @return void
     */
    private static function auto_load_files() {
        require_once MAINWP_PLUGIN_DIR . 'libs' . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'; // NOSONAR -- WP compatible.
    }

    /**
     * Seal one unit of plaintext.
     *
     * @param string $plaintext Bytes to seal (may be empty or binary).
     * @param string $key       32-byte data key.
     * @param string $aad       Additional authenticated data binding this unit's position.
     *
     * @return string Raw framing: nonce(12) . ciphertext . tag(16).
     *
     * @throws \RuntimeException When the key is not 32 bytes.
     */
    public static function seal( $plaintext, $key, $aad ) {
        if ( ! is_string( $key ) || static::KEY_LEN !== strlen( $key ) ) {
            throw new \RuntimeException( esc_html( 'Archive cipher requires a 32-byte key.' ) );
        }

        static::auto_load_files();

        $nonce = random_bytes( static::NONCE_LEN );

        $aes = new AES( 'gcm' );
        $aes->setKey( $key );
        $aes->setNonce( $nonce );
        $aes->setAAD( (string) $aad );

        $ciphertext = $aes->encrypt( (string) $plaintext );
        $tag        = $aes->getTag();

        return $nonce . $ciphertext . $tag;
    }

    /**
     * Open one sealed unit, verifying its authentication tag and AAD.
     *
     * @param string $blob Raw framing produced by seal().
     * @param string $key  32-byte data key.
     * @param string $aad  The same AAD the unit was sealed with.
     *
     * @return string The recovered plaintext.
     *
     * @throws \RuntimeException When the key is wrong, the AAD differs, the
     *                           bytes were tampered, or the blob is truncated.
     */
    public static function open( $blob, $key, $aad ) {
        if ( ! is_string( $key ) || static::KEY_LEN !== strlen( $key ) ) {
            throw new \RuntimeException( esc_html( 'Archive cipher requires a 32-byte key.' ) );
        }
        if ( ! is_string( $blob ) || strlen( $blob ) < static::OVERHEAD ) {
            throw new \RuntimeException( esc_html( 'Sealed unit is shorter than the framing overhead.' ) );
        }

        static::auto_load_files();

        $nonce      = substr( $blob, 0, static::NONCE_LEN );
        $tag        = substr( $blob, -static::TAG_LEN );
        $ciphertext = substr( $blob, static::NONCE_LEN, -static::TAG_LEN );

        $aes = new AES( 'gcm' );
        $aes->setKey( $key );
        $aes->setNonce( $nonce );
        $aes->setAAD( (string) $aad );
        $aes->setTag( $tag );

        try {
            $plaintext = $aes->decrypt( $ciphertext );
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( esc_html( 'Sealed unit failed authentication.' ) );
        }

        if ( false === $plaintext ) {
            throw new \RuntimeException( esc_html( 'Sealed unit failed authentication.' ) );
        }

        return $plaintext;
    }
}
