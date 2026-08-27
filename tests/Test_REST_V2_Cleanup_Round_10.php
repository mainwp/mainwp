<?php
/**
 * MainWP REST v2 Cleanup Round 10 Tests
 *
 * Covers the item name a lookup row is inserted under, and the force_use_ipv4
 * value plus the source private key a staging clone carries over.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_10
 */
class Test_REST_V2_Cleanup_Round_10 extends \WP_UnitTestCase {

	/**
	 * Name of the site rows this class inserts.
	 *
	 * @var string
	 */
	const SITE_NAME = 'rest-v2-cleanup-round-10 Site';

	/**
	 * Item name the lookup probe row is inserted under.
	 *
	 * @var string
	 */
	const LOOKUP_ITEM_NAME = 'round10-probe';

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Value of mainwp_stagingsites_group_id before the test replaced it.
	 *
	 * @var mixed
	 */
	protected $staging_group_id;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		// hook_clone_site() syncs the new clone against its Child when this option is
		// set, which needs a real site on the other end.
		$this->staging_group_id = get_option( 'mainwp_stagingsites_group_id' );
		delete_option( 'mainwp_stagingsites_group_id' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		if ( false !== $this->staging_group_id ) {
			update_option( 'mainwp_stagingsites_group_id', $this->staging_group_id );
		}

		// add_website() writes a per-site key file straight to disk, outside the DB
		// transaction, so deleting the mainwp_wp row alone leaves it orphaned on disk.
		// A clone is named '<SITE_NAME> - <cloneID>', so the prefix match covers it too.
		$site_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE name LIKE %s", $wpdb->esc_like( self::SITE_NAME ) . '%' ) );
		foreach ( $site_ids as $site_id ) {
			\MainWP\Dashboard\MainWP_Encrypt_Data_Lib::remove_key_file( (int) $site_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d", (int) $site_id ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE name LIKE %s", $wpdb->esc_like( self::SITE_NAME ) . '%' ) );

		// A test that stops on a failed assertion never reaches its own delete; the prefix
		// also covers the names the refusal cases must never have stored.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_lookup_item_objects WHERE item_name LIKE %s", $wpdb->esc_like( self::LOOKUP_ITEM_NAME ) . '%' ) );

		parent::tearDown();
	}

	/**
	 * Insert a site row through add_website() and leave it exactly as that call left it.
	 *
	 * @param string $url     Site URL.
	 * @param array  $params  Extra params for add_website().
	 * @param string $privkey Plaintext private key. add_website() base64-decodes what it is
	 *                        given, so the caller passing plaintext knows what to expect back
	 *                        out of decrypt_privkey(). Left empty a throwaway one is generated.
	 * @return int Site ID.
	 */
	protected function add_site_row( string $url, array $params = [], string $privkey = '' ): int {
		if ( '' === $privkey ) {
			$privkey = 'fake-priv-' . wp_generate_uuid4();
		}

		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$this->admin_user_id,
			self::SITE_NAME,
			$url,
			'admin',
			base64_encode( 'fake-pub-' . wp_generate_uuid4() ),
			base64_encode( $privkey ),
			array_merge(
				[
					// Left unset these default to null, and the columns are NOT NULL.
					'http_user' => '',
					'http_pass' => '',
				],
				$params
			)
		);

		$this->assertNotFalse( $site_id, 'add_website should insert a site row' );

		return (int) $site_id;
	}

	/**
	 * Read one site column straight from the table.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $column  Column name, from a fixed allowlist.
	 * @return string|null Column value, or null when the row is gone.
	 */
	protected function get_site_column( int $site_id, string $column ) {
		global $wpdb;

		$this->assertContains( $column, [ 'privkey', 'force_use_ipv4' ], 'unexpected column' );

		return $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ) );
	}

	/**
	 * Item 1: insert_lookup_item() writes the item name it is given, and the row it wrote
	 * reads back and deletes through the lookup API under that same name. Hardcoding 'cost'
	 * made every other caller's row unreadable through the item_name it looks up by.
	 */
	public function test_insert_lookup_item_writes_the_item_name_it_is_given(): void {
		global $wpdb;

		$db      = \MainWP\Dashboard\MainWP_DB::instance();
		$site_id = $this->add_site_row( 'https://round10-lookup-item-name.example/' );

		$lookup_id = $db->insert_lookup_item( self::LOOKUP_ITEM_NAME, 40210, 'site', $site_id );

		$this->assertGreaterThan( 0, (int) $lookup_id, 'insert_lookup_item should have inserted a lookup row' );

		$item_name = $wpdb->get_var( $wpdb->prepare( "SELECT item_name FROM {$wpdb->prefix}mainwp_lookup_item_objects WHERE lookup_id = %d", $lookup_id ) );
		$this->assertSame( self::LOOKUP_ITEM_NAME, $item_name );

		$rows = $db->get_lookup_items( self::LOOKUP_ITEM_NAME, 40210, 'site' );
		$this->assertCount( 1, $rows, 'get_lookup_items should find the row under the name it was inserted with' );
		$this->assertSame( (int) $lookup_id, (int) $rows[0]->lookup_id );

		$db->delete_lookup_items( 'object_name', [ 'item_id' => 40210, 'item_name' => self::LOOKUP_ITEM_NAME, 'object_names' => [ 'site' ] ] );
		$this->assertSame( [], $db->get_lookup_items( self::LOOKUP_ITEM_NAME, 40210, 'site' ), 'delete_lookup_items should have removed the row under the same name' );
	}

	/**
	 * Item 1, review round 1: a name that sanitizing would alter is refused rather than stored
	 * under a value get_lookup_items() and delete_lookup_items() can never match, and a name
	 * past the column length is refused instead of handing back a stale insert id.
	 */
	public function test_insert_lookup_item_refuses_a_name_it_could_not_store_as_given(): void {
		global $wpdb;

		$db      = \MainWP\Dashboard\MainWP_DB::instance();
		$site_id = $this->add_site_row( 'https://round10-lookup-item-refuse.example/' );

		// A stored row first, so a stale insert_id would be non-zero.
		$this->assertGreaterThan( 0, (int) $db->insert_lookup_item( self::LOOKUP_ITEM_NAME, 40210, 'site', $site_id ) );

		$cases = [
			'percent-encoded'  => self::LOOKUP_ITEM_NAME . '%20x',
			'past varchar(32)' => str_pad( self::LOOKUP_ITEM_NAME, 33, 'x' ),
			'not a string'     => [ self::LOOKUP_ITEM_NAME ],
		];
		foreach ( $cases as $label => $name ) {
			$this->assertFalse( $db->insert_lookup_item( $name, 40211, 'site', $site_id ), $label );
		}

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_lookup_item_objects WHERE item_id = %d", 40211 ) ), 'no refused name should have stored a row' );
	}

	/**
	 * Item 2: a staging clone copies the source's force_use_ipv4 and re-encrypts the source's
	 * private key under its own key file. The privkey assertion guards the decrypt call's site
	 * id: with the wrong id it hands back an empty string and the clone falls back to the
	 * source's ciphertext, which no key file of the clone's can open.
	 */
	public function test_clone_site_copies_force_use_ipv4_and_the_source_privkey(): void {
		$plugin_file = 'round10/round10.php';
		$key         = md5( $plugin_file . '-SNNonceAdder' );
		$privkey     = 'fake-priv-' . wp_generate_uuid4();

		$source_id = $this->add_site_row( 'https://round10-source.test/', [ 'force_use_ipv4' => 1 ], $privkey );
		$this->assertSame( '1', (string) $this->get_site_column( $source_id, 'force_use_ipv4' ), 'the source site should have been created with force_use_ipv4 on' );

		// Api_Backups_3rd_Party hooks mainwp_added_new_site to a Cloudways / GridPane
		// lookup that calls out over the network. It registers from admin_init, which this
		// harness never fires, but drop it when something did register it.
		$listener = null;
		if ( has_action( 'mainwp_added_new_site' ) && class_exists( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party' ) ) {
			$listener = [ \MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party::instance(), 'hook_added_new_site' ];
			if ( ! remove_action( 'mainwp_added_new_site', $listener, 10 ) ) {
				$listener = null;
			}
		}

		try {
			$ret = \MainWP\Dashboard\MainWP_Extensions_Handler::hook_clone_site( $plugin_file, $key, $source_id, 'round10', 'https://round10-source.test/staging/' );
		} finally {
			if ( null !== $listener ) {
				add_action( 'mainwp_added_new_site', $listener, 10, 2 );
			}
		}

		$this->assertIsArray( $ret, 'hook_clone_site should have returned a result array' );
		$this->assertArrayHasKey( 'siteid', $ret, wp_json_encode( $ret ) );
		$clone_id = (int) $ret['siteid'];
		$this->assertGreaterThan( 0, $clone_id, wp_json_encode( $ret ) );

		$this->assertSame( '1', (string) $this->get_site_column( $clone_id, 'force_use_ipv4' ), 'the clone should carry the source force_use_ipv4' );

		$clone_privkey = $this->get_site_column( $clone_id, 'privkey' );
		$this->assertSame(
			$privkey,
			\MainWP\Dashboard\MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( base64_decode( $clone_privkey ), $clone_id ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the column stores base64 of the ciphertext.
			'the clone private key should decrypt to the source plaintext under the clone key file'
		);
	}
}
