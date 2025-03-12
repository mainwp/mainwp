<?php
/**
 *
 * Encrypts & Decrypts API Keys.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Random;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Encrypt_Data_Lib
 *
 * @package MainWP/Dashboard
 */
class MainWP_Encrypt_Data_Lib { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return static class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        MainWP_Keys_Manager::auto_load_files();
        return static::$instance;
    }

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }


    /**
     * Get key filename.
     *
     * @param  int  $site_id site id.
     * @param  bool $fullpath full path.
     *
     * @return string
     */
    public static function get_key_file( $site_id, $fullpath = false ) {
        $file = 'mainwp_priv_encrypt_keys_' . $site_id;
        if ( $fullpath ) {
            MainWP_Keys_Manager::init_keys_dir();
            $key_dir = MainWP_Keys_Manager::get_keys_dir();
            return $key_dir . $file;
        }
        return $file;
    }


    /**
     * Remove key file.
     *
     * @param  int $site_id site id.
     *
     * @return void
     */
    public static function remove_key_file( $site_id ) {
        $file = static::get_key_file( $site_id );
        MainWP_Keys_Manager::instance()->delete_key_file( $file );
    }

    /**
     * Encrypt data.
     *
     * @param  mixed $value value.
     * @param  int   $site_id site_id.
     * @param  bool  $create_keys_file create_keys_file.
     * @return mixed
     */
    public function encrypt_privkey( $value, $site_id = false, $create_keys_file = false ) {
        $data = $this->encrypt_data( $value );
        if ( is_array( $data ) && ! empty( $data['en_data'] ) ) {
            if ( $create_keys_file && $site_id ) {
                static::encrypt_save_keys( $site_id, $data );
            }
            return $data;
        }
        return array();
    }

    /**
     * Decrypt data.
     *
     * @param  mixed $encrypted encrypted.
     * @param  int   $site_id site id.
     * @return mixed
     */
    public function decrypt_privkey( $encrypted, $site_id = false ) {
        $path = static::get_key_file( $site_id, true );
        if ( ! file_exists( $path ) ) {
            return '';
        }
        $values    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private key files.
        $load_keys = explode( '-', $values );
        if ( 3 <= count( $load_keys ) ) {
            return $this->decrypt_data( $encrypted, $load_keys );
        }
        return '';
    }

    /**
     * Encrypt save keys.
     *
     * @param  int   $site_id site id.
     * @param  array $encrypted_data encrypted data.
     * @return mixed
     */
    public static function encrypt_save_keys( $site_id, $encrypted_data ) {
        if ( is_array( $encrypted_data ) && ! empty( $encrypted_data['en_data'] ) ) {
            $priv_file    = static::get_key_file( $site_id );
            $keys_encoded = base64_encode( $encrypted_data['priv_key'] ) . '-' . base64_encode( $encrypted_data['en_key'] ) . '-' . base64_encode( $encrypted_data['en_iv'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode trust.
            return MainWP_Keys_Manager::instance()->save_key_file( $priv_file, $keys_encoded );
        }
        return false;
    }

    /**
     * Encrypt data.
     *
     * @param  mixed $data data.
     * @return mixed
     */
    private function encrypt_data( $data ) {
        try {
            // Load or generate RSA keys.
            $privateKey = RSA::createKey( 2048 );// Generates a new 2048-bit RSA key pair.
            $publicKey  = $privateKey->getPublicKey();

            // Step 1: Generate a random AES key.
            $aes    = new AES( 'cbc' ); // Use AES in CBC mode.
            $aesKey = Random::string( 32 ); // AES-256 key (32 bytes).
            $aesIV  = Random::string( 16 ); // 16-byte IV for CBC mode.
            $aes->setKey( $aesKey );
            $aes->setIV( $aesIV );

            // Step 2: Encrypt the large data with AES.
            $encryptedData = $aes->encrypt( $data );
            // Step 3: Encrypt the AES key and IV with RSA.
            $rsaEncryptedKey = $publicKey->encrypt( $aesKey );
            $rsaEncryptedIV  = $publicKey->encrypt( $aesIV );

            $privateKeyString = $privateKey->toString( 'PKCS8' ); // Store in PKCS8 format for easy reuse.

            return array(
                'en_data'  => $encryptedData,
                'priv_key' => $privateKeyString,
                'en_key'   => $rsaEncryptedKey,
                'en_iv'    => $rsaEncryptedIV,
            );

        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->debug( 'Encrypt Data :: [error=' . $e->getMessage() . ']' );
            return false;
        }
    }


    /**
     * Decrypt data.
     *
     * @param  mixed $encryptedData encrypted data.
     * @param  array $load_keys load keys.
     * @return mixed
     */
    public function decrypt_data( $encryptedData, $load_keys ) {
        try {
            //phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- base64_encode trust.
            $loadPrivateKeyString = base64_decode( $load_keys[0] );
            $rsaEncryptedKey      = base64_decode( $load_keys[1] );
            $rsaEncryptedIV       = base64_decode( $load_keys[2] );
            //phpcs:enable

            // Load or generate RSA keys.
            $privateKey = RSA::loadPrivateKey( $loadPrivateKeyString );
            $aes        = new AES( 'cbc' ); // Use AES in CBC mode.
            // Decrypt the AES key and IV with RSA.
            $decryptedKey = $privateKey->decrypt( $rsaEncryptedKey );
            $decryptedIV  = $privateKey->decrypt( $rsaEncryptedIV );

            // Decrypt the data with AES.
            $aes->setKey( $decryptedKey );
            $aes->setIV( $decryptedIV );

            return $aes->decrypt( $encryptedData );
        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->debug( 'Decrypt Data :: [error=' . $e->getMessage() . ']' );
            return false;
        }
    }
}
