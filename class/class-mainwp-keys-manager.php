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
class MainWP_Keys_Manager {

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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::auto_load_files(); // to fix.
		return self::$instance;
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
		require_once MAINWP_PLUGIN_DIR . 'libs' . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
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
		self::init_keys_dir();

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
		$key_dir   = self::get_keys_dir();
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
			$file_name = $prefix . sha1( sha1( $prefix . $name . time() ) . 'key_files' );
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
	private function save_key_file( $key_file, $key_val ) {
		self::init_keys_dir();
		$key_dir   = self::get_keys_dir();
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
		$key_dir = self::get_keys_dir();
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
		$encodedValue = base64_encode( $encryptedValue ); //phpcs:ignore

		return $encodedValue;
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
			$keypass = $aes->decrypt( $ciphertext );

			return $keypass;
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
	public static function init_keys_dir( $keysDir = '' ) {

		if ( '' === $keysDir ) {
			$keysDir = self::get_keys_dir();
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
				$wp_filesystem->mkdir( $keysDir, 0777 );
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
				mkdir( $keysDir, 0777, true );
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
	public function encrypt_keys_data( $data, $prefix, $key_file = false ) {

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
			include_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( ! empty( $key_file ) && is_string( $key_file ) ) {
			$file_name = $key_file;
		} elseif ( ! empty( $data ) && is_array( $data ) && ! empty( $data['file_key'] ) ) {
			$file_name = $data['file_key'];
		} else {
			$ran       = wp_rand( 0, 9990 ); // to fix repeat value.
			$file_name = $prefix . sha1( sha1( $prefix . time() . $ran ) . 'key_files' );
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
