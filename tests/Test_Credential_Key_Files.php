<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit direct-file discovery matches this test class name.
/**
 * Credential input and private key filesystem regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Helper;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test class naming convention.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real filesystem fixtures exercise the key storage boundary.

/**
 * Keep GridPane discovery real while replacing its external provider boundary.
 */
class Credential_Key_GridPane_Provider extends Api_Backups_3rd_Party {

	const FIXTURE_TOKEN = 'fixture-gridpane-credential-never-log';

	/**
	 * Return the fixture provider credential.
	 *
	 * @return string Fixture credential.
	 */
	public static function get_gridpane_api_key() {
		return self::FIXTURE_TOKEN;
	}

	/**
	 * Return an inventory response without making a network request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Provider path.
	 * @param string $accessToken Credential passed to the provider.
	 * @param array  $backup_data Request body.
	 * @return string Fixture JSON.
	 * @throws \RuntimeException When the provider receives an unexpected request.
	 */
	public static function call_gridpane_api( $method, $url, $accessToken, $backup_data = array() ) {
		if ( 'GET' !== $method || '/site?per_page=1' !== $url || self::FIXTURE_TOKEN !== $accessToken ) {
			throw new \RuntimeException( 'Unexpected GridPane fixture request.' );
		}
		return wp_json_encode( array( 'data' => array( array( 'id' => 123 ) ) ) );
	}
}

/** Supply a missing private directory without disturbing the real test key directory. */
class Credential_Key_Missing_Directory extends MainWP_Keys_Manager {

	/**
	 * Use an uncreated fixture directory.
	 *
	 * @return string Fixture directory.
	 */
	public static function get_keys_dir() {
		return sys_get_temp_dir() . '/missing-key-directory-' . wp_generate_uuid4() . '/';
	}
}

/**
 * Exercise the actual encryption filters, save handlers, DB and key files.
 */
class Test_Credential_Key_Files extends MainWP_Abilities_Test_Case {

	/**
	 * Paths created by this test only.
	 *
	 * @var array
	 */
	private $paths = array();

	/**
	 * Generated key identifiers observed after real encryption.
	 *
	 * @var array
	 */
	private $keys = array();

	/**
	 * Real key manager.
	 *
	 * @var MainWP_Keys_Manager
	 */
	private $manager;

	/** Prepare a real private key directory and record freshly encrypted keys. */
	public function setUp(): void {
		parent::setUp();
		$this->manager = MainWP_Keys_Manager::instance();
		MainWP_Keys_Manager::init_keys_dir();
		add_filter( 'mainwp_encrypt_key_value', array( $this, 'record_key' ), 99, 1 );
		$this->set_current_user_as_admin();
	}

	/** Remove this test's files without following symlinks. */
	public function tearDown(): void {
		remove_filter( 'mainwp_encrypt_key_value', array( $this, 'record_key' ), 99 );
		foreach ( $this->keys as $key ) {
			if ( is_string( $key ) && '' !== $key && '.' !== $key && '..' !== $key && basename( $key ) === $key && false === strpos( $key, '\\' ) && false === strpos( $key, "\0" ) ) {
				$this->paths[] = MainWP_Keys_Manager::get_keys_dir() . $key;
			}
		}
		foreach ( array_unique( $this->paths ) as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
		parent::tearDown();
	}

	/**
	 * Observe the real encryption result without replacing the encryptor.
	 *
	 * @param mixed $result Encryption result.
	 * @return mixed Unchanged result.
	 */
	public function record_key( $result ) {
		if ( is_array( $result ) && isset( $result['file_key'] ) ) {
			$this->keys[] = $result['file_key'];
		}
		return $result;
	}

	/**
	 * Create a fixture outside the private key directory.
	 *
	 * @return string Fixture path.
	 */
	private function outside_file() {
		$path          = dirname( MainWP_Keys_Manager::get_keys_dir() ) . '/outside_' . wp_generate_uuid4();
		$this->paths[] = $path;
		file_put_contents( $path, 'outside-fixture-must-survive' );
		return $path;
	}

	/**
	 * Generate a unique canonical basename.
	 *
	 * @return string Key basename.
	 */
	private function key_name() {
		return 'credential_fixture_' . wp_generate_uuid4();
	}

	/**
	 * Read the stored HTTP password without decrypt-on-use obscuring its envelope.
	 *
	 * @param int $id Site ID.
	 * @return string Stored column.
	 */
	private function stored_password( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT http_pass FROM %i WHERE id = %d', MainWP_DB::instance()->get_table_name( 'wp' ), $id ) );
		return $row->http_pass;
	}

	/**
	 * Use the same site DB writer as the Edit Site form.
	 *
	 * @param int   $id Site ID.
	 * @param mixed $password Submitted password.
	 * @return bool Save result.
	 */
	private function save_password( $id, $password ) {
		$site = MainWP_DB::instance()->get_website_by_id( $id );
		return MainWP_DB::instance()->update_website( $id, $site->url, $site->userid, $site->name, $site->adminname, array(), array(), '', 0, 0, 0, 1, 'global', '', null, $password );
	}

	/** Traversal input cannot read or overwrite a file outside the private directory. */
	public function test_key_paths_reject_traversal_and_regenerate_on_save() {
		$outside = $this->outside_file();
		$bad     = '../' . basename( $outside );
		$this->assertSame( '', $this->manager->get_key_val( $bad ) );
		$this->assertFalse( $this->manager->save_key_file( $bad, 'replacement-fixture' ) );
		$result = $this->manager->encrypt_keys_data( 'replacement-password', 'credential_fixture', $bad );
		$this->assertIsArray( $result );
		$this->keys[] = $result['file_key'];
		$this->assertMatchesRegularExpression( '/^credential_fixture_[a-f0-9]{40}$/D', $result['file_key'] );
		$this->assertSame( 'replacement-password', $this->manager->decrypt_keys_data( $result ) );
		$this->assertSame( 'outside-fixture-must-survive', file_get_contents( $outside ) );
	}

	/** Canonical names cannot read or truncate symlink targets outside the key directory. */
	public function test_symlink_targets_are_confined_but_deletion_removes_only_the_link() {
		$outside       = $this->outside_file();
		$key           = $this->key_name();
		$path          = MainWP_Keys_Manager::get_keys_dir() . $key;
		$this->paths[] = $path;
		$this->assertTrue( symlink( $outside, $path ) );
		$this->assertSame( '', $this->manager->get_key_val( $key ) );
		$this->assertFalse( $this->manager->save_key_file( $key, 'replacement-fixture' ) );
		$result       = $this->manager->encrypt_keys_data( 'new-password', 'credential_fixture', $key );
		$this->keys[] = $result['file_key'];
		$this->assertNotSame( $key, $result['file_key'] );
		$this->assertTrue( $this->manager->delete_key_file_with_result( $key ) );
		$this->assertFalse( is_link( $path ) );
		$this->assertSame( 'outside-fixture-must-survive', file_get_contents( $outside ) );
	}

	/** Nonregular entries are never opened as keys. */
	public function test_directory_is_not_a_key_file() {
		$key           = $this->key_name();
		$path          = MainWP_Keys_Manager::get_keys_dir() . $key;
		$this->paths[] = $path;
		mkdir( $path );
		$this->assertSame( '', $this->manager->get_key_val( $key ) );
		$this->assertFalse( $this->manager->save_key_file( $key, 'fixture' ) );
	}

	/** Exact absence is still a successful deletion when no key directory exists. */
	public function test_missing_key_directory_retains_idempotent_deletion() {
		$manager = new Credential_Key_Missing_Directory();
		$this->assertTrue( $manager->delete_key_file_with_result( $this->key_name() ) );
		$this->assertFalse( $manager->delete_key_file_with_result( '../outside' ) );
	}

	/** Historical key names and variable-size serialized RSA payloads remain readable. */
	public function test_existing_key_filename_formats_and_rsa_payload_round_trip() {
		$names = array(
			'mainwp_priv_encrypt_keys_' . wp_rand( 100000, 999999 ),
			'apibackups_plesk_' . sha1( wp_generate_uuid4() ),
			hash( 'sha256', wp_generate_uuid4() ),
			'extension_fixture-' . wp_generate_uuid4(),
		);
		$rsa   = serialize(
			array(
				'priv' => str_repeat( 'fixture-rsa-private-', 200 ),
				'pub'  => 'fixture-rsa-public',
			)
		);
		foreach ( $names as $name ) {
			$this->keys[] = $name;
			$this->assertTrue( $this->manager->save_key_file( $name, $rsa ) );
			$this->assertSame( $rsa, $this->manager->get_key_val( $name ) );
		}
	}

	/**
	 * Public prefixes have always been concatenated with a SHA-1 filename suffix.
	 *
	 * @return array Historical raw prefixes.
	 */
	public static function legacy_key_prefixes() {
		return array(
			'dotted'             => array( 'my.ext_' ),
			'leading underscore' => array( '_legacy_' ),
			'spaces'             => array( ' spaced prefix _' ),
			'punctuation'        => array( 'extension:label_' ),
			'non-ASCII'          => array( 'κλειδί_' ),
			'empty'              => array( '' ),
		);
	}

	/**
	 * Existing generated filenames remain readable, reusable and deletable.
	 *
	 * @dataProvider legacy_key_prefixes
	 * @param string $prefix Historical public prefix.
	 */
	public function test_legacy_generated_names_remain_readable_and_deletable( $prefix ) {
		$normalized    = str_ends_with( $prefix, '_' ) ? $prefix : $prefix . '_';
		$key           = $normalized . sha1( wp_generate_uuid4() );
		$path          = MainWP_Keys_Manager::get_keys_dir() . $key;
		$this->paths[] = $path;
		file_put_contents( $path, 'fixture-historical-key' );
		$this->assertSame( 'fixture-historical-key', $this->manager->get_key_val( $key ) );
		$result = apply_filters( 'mainwp_encrypt_key_value', false, 'fixture-replacement-password', $prefix, $key );
		$this->assertIsArray( $result );
		$this->assertSame( $key, $result['file_key'] );
		$this->assertSame( 'fixture-replacement-password', apply_filters( 'mainwp_decrypt_key_value', false, $result, '' ) );
		$this->assertTrue( $this->manager->delete_key_file( $key ) );
		$this->assertFileDoesNotExist( $path );
		file_put_contents( $path, 'fixture-historical-key' );
		$this->assertTrue( $this->manager->delete_key_file_with_result( $key ) );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * New hook and option encryption preserve supported raw prefixes.
	 *
	 * @dataProvider legacy_key_prefixes
	 * @param string $prefix Public prefix.
	 */
	public function test_public_encryption_preserves_safe_legacy_prefixes( $prefix ) {
		$normalized = str_ends_with( $prefix, '_' ) ? $prefix : $prefix . '_';
		$result     = apply_filters( 'mainwp_encrypt_key_value', false, 'fixture-hook-password', $prefix, false );
		$this->assertIsArray( $result );
		$this->assertStringStartsWith( $normalized, $result['file_key'] );
		$this->assertSame( 'fixture-hook-password', apply_filters( 'mainwp_decrypt_key_value', false, $result, '' ) );
		$option = 'credential_fixture_option_' . wp_generate_uuid4();
		$this->assertTrue( $this->manager->update_key_value( $option, 'fixture-option-password', $prefix ) );
		$stored       = get_option( $option );
		$this->keys[] = $stored['file_key'];
		$this->assertStringStartsWith( $normalized, $stored['file_key'] );
		$this->assertSame( 'fixture-option-password', $this->manager->get_keys_value( $option ) );
		$this->assertTrue( $this->manager->update_key_value( $option, 'fixture-option-replacement', $prefix ) );
		$this->assertSame( $stored['file_key'], get_option( $option )['file_key'] );
		$this->assertSame( 'fixture-option-replacement', $this->manager->get_keys_value( $option ) );
		$this->assertTrue( $this->manager->update_key_value( $option, '' ) );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $stored['file_key'] );
	}

	/** Filename compatibility never admits separators or arbitrary dotted files. */
	public function test_legacy_compatibility_rejects_unsafe_and_unhashed_names() {
		$hash  = str_repeat( 'a', 40 );
		$names = array( '../outside_' . $hash, 'a/b_' . $hash, 'a\\b_' . $hash, "a\0b_" . $hash, '', false, array(), str_repeat( 'a', 215 ) . '_' . $hash, 'index.php' );
		foreach ( $names as $name ) {
			$this->assertSame( '', $this->manager->get_key_val( $name ) );
			$this->assertFalse( $this->manager->save_key_file( $name, 'fixture' ) );
			$this->assertFalse( $this->manager->delete_key_file( $name ) );
			$this->assertFalse( $this->manager->delete_key_file_with_result( $name ) );
		}
	}

	/** Nonstring backup provider submissions leave both global and per-site credentials intact. */
	public function test_backup_provider_handlers_ignore_nonstring_credentials() {
		$id      = $this->create_test_site();
		$utility = Api_Backups_Utility::get_instance();
		foreach ( array( 'cpanel', 'plesk', 'kinsta' ) as $provider ) {
			$option = 'mainwp_api_backups_' . $provider . '_api_key';
			$utility->update_api_key( $provider, 'fixture-global-password' );
			$utility->update_child_api_key( $id, $provider, 'fixture-child-password' );
			$global = get_option( $option );
			$child  = Api_Backups_Helper::get_website_options( $id, array( $option ) );
			foreach ( array(
				array(
					'file_key'      => '../outside',
					'encrypted_val' => 'fixture',
				),
				array(),
				false,
				null,
			) as $invalid ) {
				$utility->update_api_key( $provider, $invalid );
				$utility->update_child_api_key( $id, $provider, $invalid );
				$this->assertSame( $global, get_option( $option ) );
				$this->assertSame( $child, Api_Backups_Helper::get_website_options( $id, array( $option ) ) );
			}
			$this->assertSame( 'fixture-global-password', $utility->get_api_key( $provider ) );
			$this->assertSame( 'fixture-child-password', $utility->get_child_api_key( $id, $provider ) );
		}
	}

	/** A previously planted backup key reference is replaced with a safe encryption result. */
	public function test_backup_save_replaces_an_unsafe_stored_filename() {
		$outside = $this->outside_file();
		$id      = $this->create_test_site();
		$utility = Api_Backups_Utility::get_instance();
		$option  = 'mainwp_api_backups_plesk_api_key';
		Api_Backups_Helper::update_website_option(
			$id,
			$option,
			wp_json_encode(
				array(
					'encrypted_val' => 'fixture',
					'file_key'      => '../' . basename( $outside ),
				)
			)
		);
		$utility->update_child_api_key( $id, 'plesk', 'fixture-new-password' );
		$this->assertSame( 'outside-fixture-must-survive', file_get_contents( $outside ) );
		$this->assertSame( 'fixture-new-password', $utility->get_child_api_key( $id, 'plesk' ) );
		$utility->update_child_api_key( $id, 'plesk', '' );
		$this->assertSame( '', $utility->get_child_api_key( $id, 'plesk' ) );
		$this->assertSame( 'outside-fixture-must-survive', file_get_contents( $outside ) );
	}

	/** A submitted envelope cannot acquire another stored record's key ownership. */
	public function test_site_save_encrypts_foreign_envelopes_and_preserves_other_keys_on_clear() {
		$first  = $this->create_test_site();
		$second = $this->create_test_site();
		$this->assertTrue( $this->save_password( $first, 'fixture-first-password' ) );
		$this->assertTrue( $this->save_password( $second, 'fixture-second-password' ) );
		$foreign = $this->stored_password( $first );
		$this->assertTrue( $this->save_password( $second, $foreign ) );
		$this->assertNotSame( $foreign, $this->stored_password( $second ) );
		$this->assertSame( $foreign, MainWP_Credential_Storage::decrypt_credential( $this->stored_password( $second ) ) );
		$this->assertTrue( $this->save_password( $second, '' ) );
		$this->assertSame( 'fixture-first-password', MainWP_Credential_Storage::decrypt_credential( $foreign ) );
	}

	/** Normal save, unchanged re-submit, array rejection and clear retain the form's behavior. */
	public function test_site_save_resave_and_clear_with_current_format_envelope() {
		$id = $this->create_test_site();
		$this->assertTrue( $this->save_password( $id, 'fixture-password' ) );
		$stored = $this->stored_password( $id );
		$this->assertSame( 'fixture-password', MainWP_Credential_Storage::decrypt_credential( $stored ) );
		$this->assertTrue( $this->save_password( $id, $stored ) );
		$this->assertSame( $stored, $this->stored_password( $id ) );
		$this->assertTrue( $this->save_password( $id, 'fixture-password' ) );
		$this->assertSame( $stored, $this->stored_password( $id ) );
		$this->assertTrue( $this->save_password( $id, array( 'file_key' => '../outside' ) ) );
		$this->assertSame( $stored, $this->stored_password( $id ) );
		$this->assertTrue( $this->save_password( $id, '' ) );
		$this->assertSame( '', $this->stored_password( $id ) );
	}

	/** A synthetic envelope produced by the base revision needs no user migration. */
	public function test_main_generated_envelope_decrypts_resaves_and_clears() {
		global $wpdb;
		$fixture      = json_decode( file_get_contents( __DIR__ . '/fixtures/credential-main-envelope.json' ), true );
		$data         = json_decode( substr( $fixture['stored'], strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
		$this->keys[] = $data['file_key'];
		$this->assertTrue( $this->manager->save_key_file( $data['file_key'], base64_decode( $fixture['key_base64'], true ) ) );
		$id = $this->create_test_site();
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'http_pass' => $fixture['stored'] ), array( 'id' => $id ) );
		$this->assertSame( $fixture['plaintext'], MainWP_Credential_Storage::decrypt_credential( $this->stored_password( $id ) ) );
		$this->assertTrue( $this->save_password( $id, $fixture['stored'] ) );
		$this->assertSame( $fixture['stored'], $this->stored_password( $id ) );
		$this->assertTrue( $this->save_password( $id, $fixture['plaintext'] ) );
		$this->assertSame( $fixture['stored'], $this->stored_password( $id ) );
		$this->assertTrue( $this->save_password( $id, '' ) );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $data['file_key'] );
	}

	/**
	 * Let the isolated WordPress harness define its own test constants.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( 'test_rest_v2_edit_encrypts_client_envelopes_as_plaintext' !== $this->getName( false ) ) {
			return parent::run( $result );
		}
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/**
	 * A dispatched REST v2 edit cannot plant a filesystem reference for a later read.
	 *
	 * REST authentication defines a process-wide compatibility constant, so isolate it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_v2_edit_encrypts_client_envelopes_as_plaintext() {
		global $wpdb, $wp_rest_server;

		$id      = $this->create_test_site();
		$outside = $this->outside_file();
		$forged  = MainWP_Credential_Storage::ENVELOPE_PREFIX . wp_json_encode(
			array(
				'file_key'      => '../' . basename( $outside ),
				'encrypted_val' => 'fixture',
			)
		);
		$key     = 'ck_' . bin2hex( random_bytes( 16 ) );
		$secret  = 'cs_' . bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_api_keys',
			array(
				'user_id'         => get_current_user_id(),
				'description'     => 'Credential regression fixture',
				'permissions'     => 'read_write',
				'consumer_key'    => mainwp_api_hash( $key ),
				'consumer_secret' => $secret,
				'truncated_key'   => substr( $key, -7 ),
				'enabled'         => 1,
				'last_access'     => current_time( 'mysql' ),
			)
		);

		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'controllers' );
		$property->setAccessible( true );
		$property->setValue( \MainWP_Rest_Server::instance(), array() );
		add_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ) );
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$prior_get    = $_GET;
		$prior_server = $_SERVER;
		$prior_query  = $GLOBALS['wp']->query_vars;
		$route        = '/mainwp/v2/sites/' . $id . '/edit';
		try {
			$_GET['consumer_key']                    = $key;
			$_GET['consumer_secret']                 = $secret;
			$_SERVER['HTTPS']                        = 'on';
			$_SERVER['REQUEST_URI']                  = '/wp-json' . $route;
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
			\MainWP_REST_Authentication::$instance   = null;
			$owner                                   = \MainWP_REST_Authentication::get_instance()->authenticate( 0 );
			$this->assertSame( get_current_user_id(), (int) $owner );
			foreach ( array( $forged, 'fixture-rest-replacement', '' ) as $submitted ) {
				$request = new \WP_REST_Request( 'PUT', $route );
				$request->set_header( 'content-type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'http_pass' => $submitted ) ) );
				$response = rest_do_request( $request );
				$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
				$this->assertSame( 1, $response->get_data()['success'] );
				$stored = $this->stored_password( $id );
				$this->assertSame( $submitted, MainWP_Credential_Storage::decrypt_credential( $stored ) );
				if ( '' !== $submitted ) {
					$this->assertNotSame( $submitted, $stored );
				}
				$this->assertSame( 'outside-fixture-must-survive', file_get_contents( $outside ) );
			}
		} finally {
			$_GET                                  = $prior_get;
			$_SERVER                               = $prior_server;
			$GLOBALS['wp']->query_vars             = $prior_query;
			$wp_rest_server                        = null;
			\MainWP_REST_Authentication::$instance = null;
		}
	}

	/** Enabled logging sees discovery diagnostics without the live provider token. */
	public function test_gridpane_discovery_does_not_log_its_credential() {
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$specific = new \ReflectionProperty( $logger, 'logSpecific' );
		$priority->setAccessible( true );
		$specific->setAccessible( true );
		$prior_priority = $priority->getValue( $logger );
		$prior_specific = $specific->getValue( $logger );
		$messages       = array();
		$capture        = static function ( $message ) use ( &$messages ) {
			$messages[] = $message;
		};
		$logger->set_log_priority( MainWP_Logger::API_BACKUPS_LOG_PRIORITY, 1 );
		add_filter( 'mainwp_logger_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			$this->assertTrue( $logger->enabled_log_priority( MainWP_Logger::API_BACKUPS_LOG_PRIORITY ) );
			$sites = Credential_Key_GridPane_Provider::gridpane_get_sites_list( 1 );
		} finally {
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_logger_to_db', '__return_true' );
			$logger->set_log_priority( $prior_priority, $prior_specific );
		}
		$this->assertCount( 1, $sites );
		$this->assertNotEmpty( $messages );
		$this->assertStringNotContainsString( Credential_Key_GridPane_Provider::FIXTURE_TOKEN, implode( "\n", $messages ) );
	}
}
