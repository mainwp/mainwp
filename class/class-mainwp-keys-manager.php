<?php
/**
 *
 * Encrypts & Decrypts API Keys.
 *
 * @package MainWP/MainWP_Keys_Manager
 */

namespace MainWP\Dashboard;

use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Random;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Keys_Manager
 *
 * @package MainWP/MainWP_Keys_Manager
 */
class MainWP_Keys_Manager { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * @return Instance class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        static::auto_load_files(); // to fix.
        return static::$instance;
    }

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method auto_load_files()
     *
     * Handle autoload files.
     */
    public static function auto_load_files() {
        require_once MAINWP_PLUGIN_DIR . 'libs' . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'; // NOSONAR -- WP compatible.
    }

    /**
     * Method get_keys_value()
     *
     * Get decrypt value.
     *
     * @param string $name Name of key.
     * @param mixed  $default_value Default value.
     *
     * @return string Decrypt value.
     */
    public function get_keys_value( $name, $default_value = false ) {
        $opt = get_option( $name );
        if ( ! empty( $opt ) && is_array( $opt ) && ! empty( $opt['file_key'] ) ) {
            return $this->decrypt_keys_data( $opt, $default_value );
        }
        return $default_value;
    }

    /**
     * Method update_key_value()
     *
     * Get decrypt value.
     *
     * @param mixed $option_name option name.
     * @param mixed $value The option value.
     * @param mixed $prefix The prefix value.
     *
     * @return string Decrypt value.
     */
    public function update_key_value( $option_name, $value = false, $prefix = 'dash_' ) {
        static::init_keys_dir();

        if ( false === $value || '' === $value ) {
            $opt = get_option( $option_name );
            if ( ! empty( $opt ) && is_array( $opt ) && ! empty( $opt['file_key'] ) ) {
                $this->delete_key_file( $opt['file_key'] );
            }
            return delete_option( $option_name );
        }

        try {
            $result = $this->encrypt_value( $value, $option_name, $prefix );
        } catch ( \Exception $ex ) {
            $err = $ex->getMessage();
            if ( is_string( $err ) ) {
                MainWP_Logger::instance()->debug( 'encrypt :: name[' . $option_name . '] :: error[' . $err . ']' );
            }
            return false;
        }

        if ( is_array( $result ) && ! empty( $result['encrypted_value'] ) ) {
            $key  = $result['key'];
            $file = $result['file_key'];
            $pw   = $result['encrypted_value'];
            if ( $this->save_key_file( $file, $key ) ) {
                $update = array(
                    'encrypted_val' => $pw,
                    'file_key'      => $file,
                );
                update_option( $option_name, $update );
                return true;
            }
        }
        return false;
    }

    /**
     * Method delete_key_file()
     *
     * Delete key file.
     *
     * @param string $file_key Name of key file.
     *
     * @return string Deleted.
     */
    public function delete_key_file( $file_key ) {
        $key_dir   = static::get_keys_dir();
        $file_path = $key_dir . $file_key;
        MainWP_Utility::delete_file( $file_path ); // delete file content key.
        return true;
    }

    /**
     * Method get_decrypt_values()
     *
     * Get decrypt value.
     *
     * @param mixed $encodedValue Encoded The value to decrypt.
     * @param mixed $key_file The value key.
     * @param mixed $default_value The default value.
     *
     * @return string Decrypt value.
     */
    private function get_decrypt_values( $encodedValue, $key_file, $default_value = '' ) {
        // find the key file, and get saved key.
        $key = $this->get_key_val( $key_file );
        if ( ! empty( $key ) ) {
            return $this->decrypt_value( $encodedValue, $key );
        }
        return $default_value;
    }

    /**
     * Method encrypt_value()
     *
     * Handle encrypt value.
     *
     * @param mixed  $keypass The value to encrypt.
     * @param string $name Option name of encrypted data.
     * @param string $prefix using for prefix key file name.
     *
     * @return string Encrypted value.
     */
    private function encrypt_value( $keypass, $name, $prefix ) {

        if ( '_' !== substr( $prefix, -1 ) ) {
            $prefix .= '_';
        }

        $opt = get_option( $name );

        if ( ! empty( $opt ) && is_array( $opt ) && ! empty( $opt['file_key'] ) ) {
            $file_name = $opt['file_key'];
        } else {
            $file_name = $prefix . sha1( sha1( $prefix . $name . time() ) . 'key_files' ); // NOSONAR - safe for salt file name.
        }

        MainWP_Logger::instance()->debug( 'encrypt :: option name[' . $name . '] :: K file[' . $file_name . ']' );

        $key = Random::string( 32 ); // supported key length: 16, 24, 32.

        $encrypted = $this->encrypt_with_key( $keypass, $key );

        return array(
            'key'             => $key,
            'file_key'        => $file_name,
            'encrypted_value' => $encrypted,
        );
    }

    /**
     * Method decrypt_value()
     *
     * Handle decrypt value.
     *
     * @param mixed $encodedValue The value to decrypt.
     * @param mixed $key Key to decrypt.
     *
     * @return string Decrypt value.
     */
    private function decrypt_value( $encodedValue, $key ) {
        return $this->decrypt_with_key( $encodedValue, $key );
    }

    /**
     * Method save_key_file()
     *
     * Handle save key passwd.
     *
     * @param mixed $key_file The value key.
     * @param mixed $key_val The value.
     *
     * @return mixed Result.
     */
    public function save_key_file( $key_file, $key_val ) {
        static::init_keys_dir();
        $key_dir   = static::get_keys_dir();
        $file_path = $key_dir . $key_file;
        $saved     = file_put_contents( $file_path, $key_val ); //phpcs:ignore
        return false === $saved ? false : true;
    }

    /**
     * Method get_key_val()
     *
     * Get decrypt value.
     *
     * @param mixed $key_file The value key.
     *
     * @return string Decrypt value.
     */
    public function get_key_val( $key_file ) {
        $key_dir = static::get_keys_dir();
        $path    = $key_dir . $key_file;
        if ( file_exists( $path ) ) {
            return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private key files.
        }
        return '';
    }


    /**
     * Method encrypt_with_key()
     *
     * Handle encrypt value.
     *
     * @param mixed $keypass The value to encrypt.
     * @param mixed $key Key to encrypt.
     *
     * @return string Encrypted value.
     */
    private function encrypt_with_key( $keypass, $key ) {

        // Generate a random IV (Initialization Vector).
        $iv = Random::string( 16 );

        // Create AES instance.
        $aes = new AES( 'gcm' ); // MODE_GCM.
        $aes->setKey( $key );

        $aes->setNonce( $iv ); // Nonces are only used in GCM mode.
        $aes->setAAD( 'authentication_data' );

        // Encrypt the value.
        $ciphertext = $aes->encrypt( $keypass );

        // Get the authentication tag.
        $tag = $aes->getTag();

        // Combine IV, ciphertext, and tag.
        $encryptedValue = $iv . $ciphertext . $tag;

        // Encode the encrypted value using base64 for storage.
        return base64_encode( $encryptedValue ); //phpcs:ignore
    }

    /**
     * Method decrypt_with_key()
     *
     * Handle decrypt value.
     *
     * @param mixed $encodedValue The string to decrypt.
     * @param mixed $key Key to decrypt.
     *
     * @return string Decrypt value.
     */
    private function decrypt_with_key( $encodedValue, $key ) {
        if ( empty( $encodedValue ) ) {
            return '';
        }
        try {
            // Decode the base64 encoded value.
            $encryptedValue = base64_decode( $encodedValue ); //phpcs:ignore

            // Extract the IV, ciphertext, and tag.
            $iv         = substr( $encryptedValue, 0, 16 );
            $ciphertext = substr( $encryptedValue, 16, -16 );
            $tag        = substr( $encryptedValue, -16 );

            // Create AES instance.
            $aes = new AES( 'gcm' ); // MODE_GCM.
            $aes->setKey( $key );

            $aes->setNonce( $iv );  // Nonces are only used in GCM mode.
            $aes->setAAD( 'authentication_data' );

            // Set the authentication tag.
            $aes->setTag( $tag );

            // Decrypt the value.
            return $aes->decrypt( $ciphertext );
        } catch ( \Exception $ex ) {
            // error.
        }
        return '';
    }

    /**
     * Method init_keys_dir()
     *
     * Check for keys directory and create it if it doesn't already exist,
     * set the file permissions and update htaccess.
     *
     * @param mixed $keysDir Keys directory.
     *
     * @return void
     */
    public static function init_keys_dir( $keysDir = '' ) { //phpcs:ignore -- NOSONAR - complex.

        if ( '' === $keysDir ) {
            $keysDir = static::get_keys_dir();
        }

        if ( ! is_string( $keysDir ) || stristr( $keysDir, '..' ) ) {
            return;
        }

        $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

            if ( ! $wp_filesystem->is_dir( $keysDir ) ) {
                // MWP-1557: 0700 preferred (owner only), 0750 fallback for shared-hosting umask edge cases. Mirrors migrate_private_filenames().
                if ( ! $wp_filesystem->mkdir( $keysDir, 0700 ) ) {
                    $wp_filesystem->mkdir( $keysDir, 0750 );
                }
            }

            if ( ! file_exists( $keysDir . '.htaccess' ) ) {
                $file_htaccess = $keysDir . '.htaccess';
                $wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
            }

            if ( ! file_exists( $keysDir . 'index.php' ) ) {
                $file_index = $keysDir . 'index.php';
                $wp_filesystem->touch( $file_index );
            }
        } else {

            //phpcs:disable
            if ( ! file_exists( $keysDir ) ) {
                // MWP-1557: 0700 preferred (owner only), 0750 fallback for shared-hosting umask edge cases. Mirrors migrate_private_filenames().
                if ( ! mkdir( $keysDir, 0700, true ) ) {
                    mkdir( $keysDir, 0750, true );
                }
            }

            if ( ! file_exists( $keysDir . '.htaccess' ) ) {
                $file_htaccess = @fopen( $keysDir . '.htaccess', 'w+' );
                fwrite( $file_htaccess, 'deny from all' );
                fclose( $file_htaccess );
            }

            if ( ! file_exists( $keysDir . 'index.php' ) ) {
                $file_index = @fopen( $keysDir . 'index.php', 'w+' );
                fclose( $file_index );
            }
            // phpcs:enable
        }
    }

    /**
     * Method get_keys_dir().
     *
     * Check for keys directory and create it if it doesn't already exist.
     * set the file permissions and update htaccess.
     *
     * @return string Keys dir.
     */
    public static function get_keys_dir() {
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        return $dirs[0] . 'pk' . DIRECTORY_SEPARATOR;
    }

    /**
     * Method register_migration_hooks()
     *
     * Register the post-upgrade hook that triggers the one-time pk/ filename
     * migration. Called from MainWP_System::activate_this_plugin() before
     * MainWP_Install::install() runs, so the action handler is registered when
     * `mainwp_db_after_update` fires.
     *
     * @return void
     */
    public static function register_migration_hooks() {
        add_action( 'mainwp_db_after_update', array( static::class, 'migrate_private_filenames' ), 10, 2 );
        add_action( 'mainwp_db_after_update', array( static::class, 'migrate_sibling_dir_perms' ), 10, 2 );
    }

    /**
     * Method migrate_private_filenames()
     *
     * One-time bulk migration for MWP-1557: rename legacy pk/ filenames
     * (`mainwp_priv_encrypt_keys_<site_id>`) to the opaque HMAC-derived names
     * computed by MainWP_System_Utility::get_private_filename(). Also tightens
     * directory permissions from the legacy 0777 to 0700 (or 0750 fallback).
     *
     * Idempotent: only matches the legacy filename pattern, so re-running on
     * already-migrated installs is a no-op. Lazy migration in
     * MainWP_Encrypt_Data_Lib::get_key_file() handles any files this bulk
     * pass might miss.
     *
     * @param string $from_version Pre-upgrade mainwp_db_version.
     * @param string $to_version   Post-upgrade mainwp_db_version.
     *
     * @return void
     */
    public static function migrate_private_filenames( $from_version, $to_version ) {
        if ( ! version_compare( $from_version, '9.0.2.0', '<' ) ) {
            return;
        }
        static::init_keys_dir();
        $key_dir = static::get_keys_dir();
        if ( ! is_dir( $key_dir ) ) {
            return;
        }

        // Tighten directory permissions; 0700 preferred, 0750 if a shared web group needs read access.
        if ( ! @chmod( $key_dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.
            @chmod( $key_dir, 0750 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.
        }

        // Rename legacy pk files to opaque HMAC-derived names.
        $entries = @scandir( $key_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort directory walk.
        if ( ! is_array( $entries ) ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( ! preg_match( '/^mainwp_priv_encrypt_keys_(\d+)$/', $entry, $m ) ) {
                continue;
            }
            $site_id  = (int) $m[1];
            $new_name = MainWP_System_Utility::get_private_filename( 'pk', $site_id, 'priv_encrypt_keys' );
            $old_path = $key_dir . $entry;
            $new_path = $key_dir . $new_name;
            if ( file_exists( $new_path ) ) {
                // New file already present (rare race); legacy is stale, remove it.
                @unlink( $old_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- cleanup of stale legacy.
                continue;
            }
            @rename( $old_path, $new_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort; lazy migration in get_key_file() handles failures.
        }
    }

    /**
     * Method migrate_sibling_dir_perms()
     *
     * One-time chmod sweep for installs that created mainwp/ subdirs before
     * MWP-1558's mkdir tightening landed in 9.0.2.0. mkdir() does not touch
     * the mode of an existing directory, so pre-fix installs keep their
     * legacy 0777 even after upgrading. Reported by Daan Kortenbach in the
     * MWP-1557/1558 follow-up sweep (MWP-1566).
     *
     * Targets the known set of subdirs the plugin manages. Idempotent:
     * chmodding an already-correct dir is a no-op. @chmod failures are
     * swallowed (Windows hosts, shared-hosting suexec mismatches, dirs
     * owned by a different system user -- all expected).
     *
     * @param string $from_version Pre-upgrade mainwp_db_version.
     * @param string $to_version   Post-upgrade mainwp_db_version.
     *
     * @return void
     */
    public static function migrate_sibling_dir_perms( $from_version, $to_version ) {
        if ( ! version_compare( $from_version, '9.0.2.1', '<' ) ) {
            return;
        }
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        if ( empty( $dirs[0] ) || ! is_dir( $dirs[0] ) ) {
            return;
        }
        $base = rtrim( $dirs[0], '/\\' ) . DIRECTORY_SEPARATOR;

        // mainwp/ root: 0755 (public-asset convention, holds index.php + subdirs).
        @chmod( rtrim( $base, '/\\' ), 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.

        // Public-asset subdirs. cost-tracker-products-icons is module-specific (constant-gated)
        // but uses the same get_mainwp_dir(..., true) public pattern, so legacy installs that ran
        // Cost Tracker pre-9.0.2.0 need the same chmod.
        foreach ( array( 'icons', 'plugin-icons', 'theme-icons', 'client-images', 'site-icons', 'themes', 'cost-tracker-products-icons' ) as $sub ) {
            $p = $base . $sub;
            if ( is_dir( $p ) ) {
                @chmod( $p, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.
            }
        }

        // Private (htaccess-protected) subdirs.
        foreach ( array( 'cookies', 'templates', 'templates' . DIRECTORY_SEPARATOR . 'emails', 'bulk' ) as $sub ) {
            $p = $base . $sub;
            if ( is_dir( $p ) ) {
                @chmod( $p, 0750 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.
            }
        }

        // Per-user dirs and all descendants: mainwp/<userid>/, /<userid>/bulk/, per-site
        // <siteid>/ dirs from get_mainwp_specific_dir($website->id), and any deeper paths
        // that backup_download_file() may have materialized via dirname($pFile) on legacy
        // installs with custom backup filenames. All private (0750).
        $chmod_recursive = function ( $dir ) use ( &$chmod_recursive ) {
            @chmod( $dir, 0750 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort hardening.
            $subs = @glob( $dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort directory walk.
            if ( is_array( $subs ) ) {
                foreach ( $subs as $s ) {
                    $chmod_recursive( $s );
                }
            }
        };

        $userdirs = @glob( $base . '[0-9]*', GLOB_ONLYDIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort directory walk.
        if ( is_array( $userdirs ) ) {
            foreach ( $userdirs as $udir ) {
                $chmod_recursive( $udir );
            }
        }
    }


    /**
     * Method encrypt_keys_data()
     *
     * Handle encrypt value.
     *
     * @param mixed  $data The value to encrypt.
     * @param string $prefix prefix key file name.
     * @param string $key_file key file name.
     *
     * @return string Encrypted value.
     */
    public function encrypt_keys_data( $data, $prefix, $key_file = false ) {  //phpcs:ignore -- NOSONAR - complex.

        if ( empty( $data ) ) {
            if ( ! empty( $key_file ) ) {
                $this->delete_key_file( $key_file );
            }
            return $data;
        }

        if ( '_' !== substr( $prefix, -1 ) ) {
            $prefix .= '_';
        }

        if ( ! function_exists( '\wp_rand' ) ) {
            include_once ABSPATH . WPINC . '/pluggable.php'; // NOSONAR - WP compatible.
        }

        if ( ! empty( $key_file ) && is_string( $key_file ) ) {
            $file_name = $key_file;
        } elseif ( ! empty( $data ) && is_array( $data ) && ! empty( $data['file_key'] ) ) {
            $file_name = $data['file_key'];
        } else {
            $ran       = wp_rand( 0, 9990 ); // to fix repeat value.
            $file_name = $prefix . sha1( sha1( $prefix . time() . $ran ) . 'key_files' ); // NOSONAR - safe for salt file name.
        }

        MainWP_Logger::instance()->debug( 'encrypt :: K file[' . $file_name . ']' );

        try {
            $key       = Random::string( 32 ); // supported key length: 16, 24, 32.
            $encrypted = $this->encrypt_with_key( $data, $key );
            $result    = array(
                'key'             => $key,
                'file_key'        => $file_name,
                'encrypted_value' => $encrypted,
            );
        } catch ( \Exception $ex ) {
            $err = $ex->getMessage();
            if ( is_string( $err ) ) {
                MainWP_Logger::instance()->debug( 'encrypt :: error[' . $err . ']' );
            }
            return false;
        }

        if ( is_array( $result ) && ! empty( $result['encrypted_value'] ) ) {
            $key  = $result['key'];
            $file = $result['file_key'];
            $pw   = $result['encrypted_value'];
            if ( $this->save_key_file( $file, $key ) ) {
                return array(
                    'encrypted_val' => $pw,
                    'file_key'      => $file,
                );
            }
        }
        return false;
    }


    /**
     * Method decrypt_keys_data()
     *
     * Get decrypt value.
     *
     * @param string $encrypted Name of key.
     * @param mixed  $default_value Default value.
     *
     * @return string Decrypt value.
     */
    public function decrypt_keys_data( $encrypted, $default_value = false ) {
        if ( is_array( $encrypted ) && ! empty( $encrypted['file_key'] ) && ! empty( $encrypted['encrypted_val'] ) ) {
            try {
                return $this->get_decrypt_values( $encrypted['encrypted_val'], $encrypted['file_key'], $default_value );
            } catch ( \Exception $ex ) {
                $err = $ex->getMessage();
                if ( is_string( $err ) ) {
                    MainWP_Logger::instance()->debug( 'decrypt :: error[' . $err . ']' );
                }
                return false;
            }
        }
        return $default_value;
    }
}
