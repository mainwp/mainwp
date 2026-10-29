<?php
/**
 * API Backups reads keep provider credentials inside the Dashboard.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Api_Backups;
use MainWP\Dashboard\MainWP_DB;

/** Exercise stored reads through real WordPress abilities and SQL. */
class MainWP_Api_Backups_Read_Abilities_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Fake credential strings checked as complete values and four-byte fragments.
	 *
	 * @var array
	 */
	private $sentinels = array( 'FAKEKEY-SENTINEL-1', 'Bearer fake.jwt.token', 'password=FAKEPASS', 'https://zqx-sentinel.test/?token=FAKE', '/fake/private/key-file.pem' );

	/**
	 * Supported provider names.
	 *
	 * @var array
	 */
	private $providers = array( 'cloudways', 'gridpane', 'vultr', 'linode', 'digitalocean', 'cpanel', 'plesk', 'kinsta' );

	/**
	 * Owned fixture sites by provider.
	 *
	 * @var array
	 */
	private $sites = array();

	/** Prepare real rows without invoking any provider or encryption helper. */
	public function setUp(): void {
		parent::setUp();
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		if ( ! wp_get_ability( 'mainwp/list-api-backups-providers-v1' ) ) {
			MainWP_Abilities_Api_Backups::register();
		}
		update_option( 'timezone_string', 'America/New_York' );
		$labels = array( 'Cloudways', 'GridPane', 'Vultr', 'Linode', 'DigitalOcean', 'cPanel', 'Plesk', 'Kinsta' );
		foreach ( $this->providers as $index => $slug ) {
			$secret = $this->sentinels[ $index % count( $this->sentinels ) ];
			update_option(
				'mainwp_api_backups_' . $slug . '_api_key',
				array(
					'encrypted_val' => $secret,
					'file_key'      => $this->sentinels[4],
				)
			);
			update_option( 'mainwp_enable_' . $slug . '_api', 1 === $index ? 0 : 1 );
			$id                   = $this->create_test_site( array( 'name' => '<b>Readable ' . $slug . '</b>' ) );
			$this->sites[ $slug ] = $id;
			$this->put_site_option( $id, 'mainwp_3rd_party_api', $labels[ $index ] );
			$this->put_site_option( $id, 'mainwp_3rd_party_' . $slug . '_last_backup', '1761782400' );
			$this->put_site_option(
				$id,
				'mainwp_api_backups_' . $slug . '_api_key',
				wp_json_encode(
					array(
						'encrypted_val' => $secret,
						'file_key'      => $this->sentinels[4],
					)
				)
			);
			$this->put_site_option( $id, 'mainwp_3rd_party_instance_id', $this->sentinels[0] );
			$this->put_site_option( $id, 'mainwp_3rd_party_app_id', $this->sentinels[1] );
		}
		update_option( 'mainwp_cloudways_api_account_email', 'fake-private@zqx-sentinel.test' );
	}

	/**
	 * Set a fixture option directly in the dedicated WordPress test database.
	 *
	 * @param int    $id Site ID.
	 * @param string $name Option name.
	 * @param string $value Fake value.
	 */
	private function put_site_option( $id, $name, $value ): void {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_options' );
		$wpdb->delete(
			$table,
			array(
				'wpid' => $id,
				'name' => $name,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'wpid'  => $id,
				'name'  => $name,
				'value' => $value,
			)
		);
	}

	/**
	 * Assert the exact public keys.
	 *
	 * @param array $expected Expected keys.
	 * @param array $value Response object.
	 */
	private function assert_keys( $expected, $value ): void {
		$actual = array_keys( $value );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Check every response byte against planted fake secret fragments.
	 *
	 * @param mixed $value Response or error.
	 */
	private function assert_no_secrets( $value ): void {
		$json = wp_json_encode( $value );
		foreach ( $this->sentinels as $index => $sentinel ) {
			$last_offset = strlen( $sentinel ) - 4;
			for ( $offset = 0; $offset <= $last_offset; ++$offset ) {
				$this->assertStringNotContainsString( substr( $sentinel, $offset, 4 ), $json, 'Secret fragment fixture ' . $index . ' offset ' . $offset );
			}
		}
	}

	/** Both real registrations have exact output schemas and read-only annotations. */
	public function test_registration_and_provider_reads(): void {
		foreach ( array( 'mainwp/list-api-backups-providers-v1', 'mainwp/list-api-backups-site-status-v1' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
			$this->assertTrue( $ability->get_meta()['annotations']['idempotent'] );
		}
		$result = $this->execute_ability( 'mainwp/list-api-backups-providers-v1' );
		$this->assertNotWPError( $result );
		$this->assert_keys( array( 'providers' ), $result );
		$this->assertCount( 8, $result['providers'] );
		foreach ( $result['providers'] as $row ) {
			$this->assert_keys( array( 'provider', 'name', 'connected', 'site_count' ), $row );
			$this->assertSame( 1, $row['site_count'] );
			$this->assertSame( 'gridpane' !== $row['provider'], $row['connected'] );
		}
		$this->assert_no_secrets( $result );
	}

	/** Token-only Cloudways and individual-only credentials remain configured. */
	public function test_supported_configuration_sources(): void {
		delete_option( 'mainwp_api_backups_cloudways_api_key' );
		update_option(
			'mainwp_api_backups_cloudways_access_token',
			array(
				'encrypted_val' => $this->sentinels[1],
				'file_key'      => $this->sentinels[4],
			)
		);
		foreach ( array( 'cpanel', 'plesk', 'kinsta' ) as $slug ) {
			delete_option( 'mainwp_api_backups_' . $slug . '_api_key' );
		}
		$result = $this->execute_ability( 'mainwp/list-api-backups-providers-v1' );
		$this->assertNotWPError( $result );
		$this->assertSame( $this->providers, array_column( $result['providers'], 'provider' ) );
		$this->assert_no_secrets( $result );
	}

	/** The pinned inventory covers the native per-site credential storage contract. */
	public function test_provider_inventory_matches_native_storage(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/modules/api-backups/classes/class-api-backups-utility.php' );
		$schema = wp_get_ability( 'mainwp/list-api-backups-site-status-v1' )->get_input_schema();
		$actual = $schema['properties']['provider']['enum'];
		sort( $actual );
		foreach ( array( 'get_child_api_key', 'update_child_api_key' ) as $method ) {
			$this->assertSame( 1, preg_match( '/public function ' . $method . '\\([^)]*\\)\\s*\\{\\s*\\$names\\s*=\\s*array\\((.*?)\\);/s', $source, $matches ), $method . ' supported-name array' );
			preg_match_all( "/'([^']+)'/", $matches[1], $names );
			$expected = $names[1];
			sort( $expected );
			$this->assertSame( $expected, $actual, $method . ' provider coverage' );
		}
	}

	/** Every supported individual-only envelope is recognized by the real SQL joins. */
	public function test_all_individual_only_providers_and_unknown_storage(): void {
		foreach ( $this->providers as $slug ) {
			delete_option( 'mainwp_api_backups_' . $slug . '_api_key' );
		}
		delete_option( 'mainwp_api_backups_cloudways_access_token' );
		$result = $this->execute_ability( 'mainwp/list-api-backups-providers-v1' );
		$this->assertNotWPError( $result );
		$this->assertSame( $this->providers, array_column( $result['providers'], 'provider' ) );
		foreach ( $result['providers'] as $row ) {
			$this->assertSame( 1, $row['site_count'] );
			$this->assertSame( 'gridpane' !== $row['provider'], $row['connected'] );
		}
		$this->assert_no_secrets( $result );
		$unknown = $this->create_test_site( array( 'name' => 'Unknown provider' ) );
		$this->put_site_option( $unknown, 'mainwp_3rd_party_api', $this->sentinels[0] );
		$this->put_site_option( $unknown, 'mainwp_3rd_party_' . strtolower( $this->sentinels[0] ) . '_last_backup', '1761782400' );
		$sites = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1' );
		$this->assertNotWPError( $sites );
		$rows = array_column( $sites['sites'], null, 'site_id' );
		foreach ( $this->sites as $slug => $id ) {
			$this->assertSame( $slug, $rows[ $id ]['provider'] );
		}
		$this->assertNull( $rows[ $unknown ]['provider'] );
		$this->assertNull( $rows[ $unknown ]['last_backup'] );
		$this->assert_no_secrets( $sites );
	}

	/** Serialized empty credentials do not configure providers or clear site assignments. */
	public function test_serialized_empty_individual_credentials(): void {
		foreach ( $this->providers as $slug ) {
			delete_option( 'mainwp_api_backups_' . $slug . '_api_key' );
		}
		delete_option( 'mainwp_api_backups_cloudways_access_token' );
		foreach ( array( 'a:0:{}', 'b:0;', 'N;' ) as $empty_value ) {
			foreach ( $this->sites as $slug => $id ) {
				$this->put_site_option( $id, 'mainwp_api_backups_' . $slug . '_api_key', $empty_value );
			}
			$result = $this->execute_ability( 'mainwp/list-api-backups-providers-v1' );
			$this->assertNotWPError( $result );
			$this->assertSame( array(), $result['providers'], $empty_value );
			$sites = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1' );
			$this->assertNotWPError( $sites );
			$rows = array_column( $sites['sites'], null, 'site_id' );
			foreach ( $this->sites as $slug => $id ) {
				$this->assertSame( $slug, $rows[ $id ]['provider'] );
			}
			$this->assert_no_secrets( $result );
			$this->assert_no_secrets( $sites );
		}
	}

	/** Paging accepts query strings and cannot overflow before returning an empty page. */
	public function test_paging_schema_and_stored_dates(): void {
		$result = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1' );
		$this->assertNotWPError( $result );
		$this->assert_keys( array( 'sites', 'page', 'per_page', 'total', 'hidden_site_count' ), $result );
		$this->assertSame( 8, $result['total'] );
		$this->assertSame( 'Readable cloudways', $result['sites'][0]['name'] );
		$this->assertSame( '2025-10-30T04:00:00+00:00', $result['sites'][0]['last_backup'] );
		foreach ( $result['sites'] as $row ) {
			$this->assert_keys( array( 'site_id', 'name', 'provider', 'last_backup', 'last_status' ), $row );
			$this->assertSame( 'unknown', $row['last_status'] );
		}
		$this->assert_no_secrets( $result );
		foreach ( array( 2, '2', PHP_INT_MAX, str_repeat( '9', 100 ) ) as $page ) {
			$input  = array(
				'page'     => $page,
				'per_page' => 2,
			);
			$schema = wp_get_ability( 'mainwp/list-api-backups-site-status-v1' )->get_input_schema();
			$this->assertTrue( rest_validate_value_from_schema( $input, $schema, 'input' ) );
			$clean  = rest_sanitize_value_from_schema( $input, $schema, 'input' );
			$output = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1', $clean );
			$this->assertNotWPError( $output );
			$this->assertSame( 8, $output['total'] );
			$this->assertSame( $page > 8 ? array() : array( $this->sites['vultr'], $this->sites['linode'] ), array_column( $output['sites'], 'site_id' ) );
			$this->assert_no_secrets( $output );
		}
	}

	/** Provider filtering, unknown data and never-backed-up sites stay allowlisted. */
	public function test_filters_empty_defaults_and_undecodable_data(): void {
		foreach ( $this->providers as $slug ) {
			$result = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1', array( 'provider' => $slug ) );
			$this->assertNotWPError( $result );
			$this->assertSame( 1, $result['total'] );
			$this->assertSame( $slug, $result['sites'][0]['provider'] );
			$this->assert_no_secrets( $result );
		}
		$this->put_site_option( $this->sites['cloudways'], 'mainwp_3rd_party_cloudways_last_backup', 'not-a-date' );
		$result = $this->execute_ability( 'mainwp/list-api-backups-site-status-v1', array( 'provider' => 'cloudways' ) );
		$this->assertNull( $result['sites'][0]['last_backup'] );
		$this->assert_no_secrets( $result );
		$never  = $this->create_test_site( array( 'name' => 'Never' ) );
		$result = $this->execute_ability(
			'mainwp/list-api-backups-site-status-v1',
			array(
				'page'     => 2,
				'per_page' => 8,
			)
		);
		$this->assertSame( $never, $result['sites'][0]['site_id'] );
		$this->assertNull( $result['sites'][0]['provider'] );
		$this->assertNull( $result['sites'][0]['last_backup'] );
		$this->assert_no_secrets( $result );
	}

	/** Ordinary users and denied settings rights cannot read either ability directly. */
	public function test_admin_and_settings_right_are_required(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( 'execute_providers', 'execute_site_status' ) as $method ) {
			$result = MainWP_Abilities_Api_Backups::$method();
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
			$this->assert_no_secrets( $result );
		}
		$this->set_current_user_as_admin();
		$deny = static function ( $grant, $type, $right ) {
			return 'dashboard' === $type && 'manage_api_backups' === $right ? false : $grant;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$this->assertWPError( MainWP_Abilities_Api_Backups::execute_providers() );
			$this->assertWPError( MainWP_Abilities_Api_Backups::execute_site_status() );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
	}

	/** Global indicators preserve the REST settings full-scope boundary. */
	public function test_provider_read_rejects_site_and_group_restrictions(): void {
		foreach ( array( 'mainwp_currentuserallowedaccesssites', 'mainwp_currentuserallowedaccessgroups' ) as $hook ) {
			$restrict = static function () {
				return array( 1 );
			};
			add_filter( $hook, $restrict );
			try {
				$result = MainWP_Abilities_Api_Backups::execute_providers();
				$this->assertWPError( $result );
				$this->assertSame( 'mainwp_restricted_account', $result->get_error_code() );
				$this->assert_no_secrets( $result );
			} finally {
				remove_filter( $hook, $restrict );
			}
		}
	}

	/** Both site checks filter hidden targets before paging and counting. */
	public function test_site_access_filters_and_hidden_count(): void {
		$hidden = $this->sites['cloudways'];
		$deny   = static function ( $grant, $id ) use ( $hidden ) {
			return $id === $hidden ? false : $grant;
		};
		add_filter( 'mainwp_check_site_access', $deny, 10, 2 );
		try {
			$result = MainWP_Abilities_Api_Backups::execute_site_status();
			$this->assertNotWPError( $result );
			$this->assertSame( 7, $result['total'] );
			$this->assertSame( 1, $result['hidden_site_count'] );
			$this->assertNotContains( $hidden, array_column( $result['sites'], 'site_id' ) );
			$this->assert_no_secrets( $result );
		} finally {
			remove_filter( 'mainwp_check_site_access', $deny, 10 );
		}
	}

	/** Reads neither mutate storage nor trigger provider, child, mail or log hooks. */
	public function test_read_purity_and_final_text_pass(): void {
		global $wpdb;
		$before_options      = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
		$table               = MainWP_DB::instance()->get_table_name( 'wp_options' );
		$before_site_options = $wpdb->get_results( $wpdb->prepare( 'SELECT wpid, name, value FROM %i ORDER BY wpid, name', $table ), ARRAY_A );
		$effects             = array();
		$record              = static function () use ( &$effects ) {
			$effects[] = 'unexpected effect';
			return false;
		};
		foreach ( array( 'pre_http_request', 'pre_wp_mail', 'mainwp_fetch_url_authed_pre', 'mainwp_log_action' ) as $hook ) {
			add_filter( $hook, $record );
		}
		try {
			foreach ( $this->sentinels as $sentinel ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'name' => $sentinel ), array( 'id' => $this->sites['cloudways'] ) );
				$result = MainWP_Abilities_Api_Backups::execute_site_status();
				$this->assertSame( '', $result['sites'][0]['name'] );
				$this->assert_no_secrets( $result );
			}
			foreach ( array( 'Basic SEO checklist', 'Shop / Blog' ) as $name ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'name' => $name ), array( 'id' => $this->sites['cloudways'] ) );
				$this->assertSame( $name, MainWP_Abilities_Api_Backups::execute_site_status()['sites'][0]['name'] );
			}
			$this->assert_no_secrets( MainWP_Abilities_Api_Backups::execute_providers() );
		} finally {
			foreach ( array( 'pre_http_request', 'pre_wp_mail', 'mainwp_fetch_url_authed_pre', 'mainwp_log_action' ) as $hook ) {
				remove_filter( $hook, $record );
			}
		}
		$this->assertSame( array(), $effects );
		$this->assertSame( $before_options, $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) );
		$this->assertSame( $before_site_options, $wpdb->get_results( $wpdb->prepare( 'SELECT wpid, name, value FROM %i ORDER BY wpid, name', $table ), ARRAY_A ) );
	}
}
