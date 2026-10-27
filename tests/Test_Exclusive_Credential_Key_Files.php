<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit discovery.
/**
 * Generated credential keys preserve files already in use.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

/**
 * Pin only this fixture's filename draws; other tests use WordPress randomness.
 *
 * @param int $min Minimum value.
 * @param int $max Maximum value.
 * @return int Random value.
 */
function wp_rand( $min = 0, $max = 0 ) {
	if ( null !== Tests\Test_Exclusive_Credential_Key_Files::$draws ) {
		++Tests\Test_Exclusive_Credential_Key_Files::$draw_count;
		return array_shift( Tests\Test_Exclusive_Credential_Key_Files::$draws ) ?? 11;
	}
	return \wp_rand( $min, $max );
}

/**
 * Write a real partial key only when this fixture requests a stream failure.
 *
 * @param resource $handle Open stream.
 * @param string   $data Bytes to write.
 * @return int|false Bytes written, or failure.
 */
function fwrite( $handle, $data ) {
	$limit = Tests\Test_Exclusive_Credential_Key_Files::$write_limit;
	if ( null !== $limit ) {
		return 0 === $limit ? false : \fwrite( $handle, substr( $data, 0, $limit ) );
	}
	return \fwrite( $handle, $data );
}

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Filename randomness belongs to the production namespace.

require_once __DIR__ . '/fixtures/dashboard-time.php';

use MainWP\Dashboard\MainWP_Keys_Manager;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Shared-process function overrides belong with these tests.
/** Real files and encryption through the two public creation paths. */
class Test_Exclusive_Credential_Key_Files extends \WP_UnitTestCase {
	/**
	 * Fixed second for this fixture, disabled outside a test.
	 *
	 * @var int|null
	 */
	public static $fixed_time = null;
	/**
	 * Controlled filename draws, disabled outside a test.
	 *
	 * @var array|null
	 */
	public static $draws = null;
	/**
	 * Filename draws consumed.
	 *
	 * @var int
	 */
	public static $draw_count = 0;
	/**
	 * Partial write size, disabled outside failure fixtures.
	 *
	 * @var int|null
	 */
	public static $write_limit = null;
	/**
	 * Real manager.
	 *
	 * @var MainWP_Keys_Manager
	 */
	private $manager;
	/**
	 * Unique prefix.
	 *
	 * @var string
	 */
	private $prefix;
	/**
	 * Option owned by this test.
	 *
	 * @var string
	 */
	private $option;
	/**
	 * Previous clock override.
	 *
	 * @var int|null
	 */
	private $previous_time;

	/** Prepare the directory before deterministic filename generation. */
	public function set_up() {
		parent::set_up();
		$this->manager = MainWP_Keys_Manager::instance();
		MainWP_Keys_Manager::init_keys_dir();
		$this->prefix        = 'exclusive_' . str_replace( '-', '', wp_generate_uuid4() ) . '_';
		$this->option        = $this->prefix . 'option';
		$this->previous_time = self::$fixed_time;
		self::$fixed_time    = 1793100000;
		self::$draw_count    = 0;
		add_filter( 'pre_http_request', array( $this, 'block_network' ) );
	}

	/** Remove only this fixture's entries and reset shared-process overrides. */
	public function tear_down() {
		self::$draws       = null;
		self::$write_limit = null;
		self::$fixed_time  = $this->previous_time;
		foreach ( glob( MainWP_Keys_Manager::get_keys_dir() . $this->prefix . '*' ) as $path ) {
			unlink( $path );
		}
		delete_option( $this->option );
		remove_filter( 'pre_http_request', array( $this, 'block_network' ) );
		parent::tear_down();
	}

	/**
	 * Network requests are forbidden in these fixtures.
	 *
	 * @return \WP_Error
	 */
	public function block_network() {
		return new \WP_Error( 'exclusive_fixture_network', 'Network request refused by fixture.' );
	}

	/**
	 * Expected first or retry basename.
	 *
	 * @param string   $kind Public path.
	 * @param int|null $draw Retry draw, or null for the option's first name.
	 * @return string Basename.
	 */
	private function filename( $kind, $draw = null ) {
		$seed = $this->prefix . ( 'option' === $kind ? $this->option : '' ) . self::$fixed_time;
		return $this->prefix . sha1( sha1( $seed . $draw ) . 'key_files' );
	}

	/**
	 * Exercise a public writer and obtain its envelope without printing values.
	 *
	 * @param string $kind Public path.
	 * @return array|false Envelope or failure.
	 */
	private function create( $kind ) {
		if ( 'option' === $kind ) {
			return $this->manager->update_key_value( $this->option, 'new-fixture', $this->prefix ) ? get_option( $this->option ) : false;
		}
		return $this->manager->encrypt_keys_data( 'new-fixture', $this->prefix );
	}

	/**
	 * SHA-256 and counts are safe to retain; key bytes and values never leave PHP.
	 *
	 * @return array Basename to hash.
	 */
	private function hashes() {
		$hashes = array();
		foreach ( glob( MainWP_Keys_Manager::get_keys_dir() . $this->prefix . '*' ) as $path ) {
			$hashes[ basename( $path ) ] = hash_file( 'sha256', $path );
		}
		ksort( $hashes );
		return $hashes;
	}

	/**
	 * Both public generators.
	 *
	 * @return array
	 */
	public function generators() {
		return array(
			'data'   => array( 'data' ),
			'option' => array( 'option' ),
		);
	}

	/**
	 * A collision must leave the old credential readable.
	 *
	 * @dataProvider generators
	 * @param string $kind Public path.
	 */
	public function test_collision_retries_without_replacing_live_key( $kind ) {
		$this->collision( $kind, false );
	}

	/**
	 * Five occupied candidates fail without publishing or changing files.
	 *
	 * @dataProvider generators
	 * @param string $kind Public path.
	 */
	public function test_collision_exhaustion_preserves_all_files( $kind ) {
		$this->collision( $kind, true );
	}

	/**
	 * Seed real decryptable credentials at every occupied candidate.
	 *
	 * @param string $kind Public path.
	 * @param bool   $exhaust Whether all five candidates are occupied.
	 */
	private function collision( $kind, $exhaust ) {
		$draws = $exhaust ? array( 11, 12, 13, 14, 15 ) : array( 11, 12 );
		$names = array( $this->filename( $kind, 'data' === $kind ? 11 : null ) );
		if ( $exhaust ) {
			foreach ( 'data' === $kind ? array( 12, 13, 14, 15 ) : array( 11, 12, 13, 14 ) as $draw ) {
				$names[] = $this->filename( $kind, $draw );
			}
		}
		$old = array();
		foreach ( $names as $name ) {
			$old[] = $this->manager->encrypt_keys_data( 'old-fixture', $this->prefix, $name );
		}
		$before      = $this->hashes();
		self::$draws = $draws;
		$result      = $this->create( $kind );
		$after       = $this->hashes();
		$decrypts    = array_map(
			function ( $envelope ) {
				return 'old-fixture' === $this->manager->decrypt_keys_data( $envelope );
			},
			$old
		);
		$evidence    = getenv( 'MWP_2016_EVIDENCE' );
		if ( $evidence ) {
			file_put_contents(
				$evidence,
				wp_json_encode(
					array(
						'kind'           => $kind,
						'exhaustion'     => $exhaust,
						'before'         => $before,
						'after'          => $after,
						'count_before'   => count( $before ),
						'count_after'    => count( $after ),
						'old_decrypts'   => $decrypts,
						'returned_false' => false === $result,
						'draws'          => self::$draw_count,
					)
				) . "\n",
				FILE_APPEND
			);
		}
		foreach ( $before as $name => $hash ) {
			$this->assertSame( $hash, $after[ $name ], 'Existing key hash changed.' );
		}
		$this->assertNotContains( false, $decrypts, 'An old credential no longer decrypts.' );
		$this->assertCount( count( $before ) + ( $exhaust ? 0 : 1 ), $after );
		if ( $exhaust ) {
			$this->assertTrue( false === $result, 'No envelope may be published on failure.' );
			$this->assertFalse( get_option( $this->option ) );
			$this->assertSame( 'data' === $kind ? 5 : 4, self::$draw_count );
		} else {
			$this->assertIsArray( $result );
			$this->assertNotContains( $result['file_key'], $names );
			$this->assertTrue( 'new-fixture' === $this->manager->decrypt_keys_data( $result ) );
		}
	}

	/**
	 * First creation keeps the original name and a decryptable envelope.
	 *
	 * @dataProvider generators
	 * @param string $kind Public path.
	 */
	public function test_normal_create_and_empty_delete( $kind ) {
		self::$draws = array( 11 );
		$result      = $this->create( $kind );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'encrypted_val', 'file_key' ), array_keys( $result ) );
		$this->assertSame( $this->filename( $kind, 'data' === $kind ? 11 : null ), $result['file_key'] );
		$this->assertMatchesRegularExpression( '/^' . $this->prefix . '[a-f0-9]{40}$/D', $result['file_key'] );
		$this->assertTrue( 'new-fixture' === $this->manager->decrypt_keys_data( $result ) );
		$this->assertCount( 1, $this->hashes() );
		if ( 'option' === $kind ) {
			$this->assertTrue( $this->manager->update_key_value( $this->option, '' ) );
			$this->assertFalse( get_option( $this->option ) );
		} else {
			$this->assertSame( '', $this->manager->encrypt_keys_data( '', $this->prefix, $result['file_key'] ) );
		}
		$this->assertCount( 0, $this->hashes() );
	}

	/** Explicit and stored names continue to replace key bytes in place. */
	public function test_intentional_reuse_and_public_save_are_unchanged() {
		$first = $this->manager->encrypt_keys_data( 'old-fixture', $this->prefix );
		$hash  = $this->hashes();
		$next  = $this->manager->encrypt_keys_data( 'new-fixture', $this->prefix, $first['file_key'] );
		$this->assertSame( $first['file_key'], $next['file_key'] );
		$this->assertNotSame( $hash, $this->hashes() );
		$this->assertTrue( 'new-fixture' === $this->manager->decrypt_keys_data( $next ) );
		update_option( $this->option, $next );
		$this->assertTrue( $this->manager->update_key_value( $this->option, 'stored-fixture', $this->prefix ) );
		$this->assertSame( $next['file_key'], get_option( $this->option )['file_key'] );
		$this->assertTrue( 'stored-fixture' === $this->manager->get_keys_value( $this->option ) );
		$this->assertCount( 1, $this->hashes() );
		$this->assertTrue( $this->manager->save_key_file( $next['file_key'], 'public-save-fixture' ) );
		$this->assertTrue( 'public-save-fixture' === $this->manager->get_key_val( $next['file_key'] ) );
	}
	/** Array payload rejection must still select the envelope name without generating one. */
	public function test_envelope_name_selection_preserves_existing_array_failure() {
		$old              = $this->manager->encrypt_keys_data( 'old-fixture', $this->prefix );
		$before           = $this->hashes();
		self::$draws      = array( 11 );
		self::$draw_count = 0;
		try {
			$result = $this->manager->encrypt_keys_data( $old, $this->prefix );
			$this->assertTrue( false === $result, 'No envelope may be published on failure.' );
		} catch ( \TypeError $error ) {
			$this->assertStringContainsString( 'array', $error->getMessage() );
		}
		$this->assertSame( 0, self::$draw_count );
		$this->assertSame( $before, $this->hashes() );
		$this->assertTrue( 'old-fixture' === $this->manager->decrypt_keys_data( $old ) );
	}

	/**
	 * Public generators and write failure modes.
	 *
	 * @return array
	 */
	public function write_failures() {
		return array(
			'data-error'   => array( 'data', 0 ),
			'data-short'   => array( 'data', 16 ),
			'option-error' => array( 'option', 0 ),
			'option-short' => array( 'option', 16 ),
		);
	}

	/**
	 * Incomplete writes remove only the new entry and publish no credential.
	 *
	 * @dataProvider write_failures
	 * @param string $kind Public generator.
	 * @param int    $limit Write limit.
	 */
	public function test_failed_write_removes_only_its_created_file( $kind, $limit ) {
		$old               = $this->manager->encrypt_keys_data( 'old-fixture', $this->prefix, $this->prefix . 'existing' );
		$before            = $this->hashes();
		self::$draws       = array( 11 );
		self::$write_limit = $limit;
		$result            = $this->create( $kind );
		self::$write_limit = null;
		$after             = $this->hashes();
		$decrypts          = 'old-fixture' === $this->manager->decrypt_keys_data( $old );
		$evidence          = getenv( 'MWP_2016_EVIDENCE' );
		if ( $evidence ) {
			file_put_contents(
				$evidence,
				wp_json_encode(
					array(
						'kind'           => $kind,
						'write_limit'    => $limit,
						'before'         => $before,
						'after'          => $after,
						'count_before'   => count( $before ),
						'count_after'    => count( $after ),
						'old_decrypts'   => $decrypts,
						'returned_false' => false === $result,
					)
				) . "\n",
				FILE_APPEND
			);
		}
		$this->assertTrue( false === $result, 'No envelope may be published on failure.' );
		$this->assertSame( $before, $after );
		$this->assertTrue( $decrypts );
		$this->assertFalse( get_option( $this->option ) );
	}
}
