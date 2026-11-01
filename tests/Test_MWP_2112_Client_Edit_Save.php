<?php
/**
 * MWP-2112 Edit Client save keeps the stored Added on time and skips blank client fields.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_DB_Client;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_2112_Client_Edit_Save
 */
class Test_MWP_2112_Client_Edit_Save extends \WP_UnitTestCase {

	/**
	 * Client IDs created by this test.
	 *
	 * @var int[]
	 */
	private $client_ids = array();

	/**
	 * Client field IDs created by this test.
	 *
	 * @var int[]
	 */
	private $field_ids = array();

	/**
	 * Set up an administrator; client saves require that role for secure_request().
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client_ids = array();
		$this->field_ids  = array();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Remove clients, fields and values created by a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		$_POST    = array();
		$_REQUEST = array();

		foreach ( $this->client_ids as $client_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_clients', array( 'client_id' => $client_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_clients_field_values', array( 'value_client_id' => $client_id ), array( '%d' ) );
		}
		foreach ( $this->field_ids as $field_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_clients_fields', array( 'field_id' => $field_id ), array( '%d' ) );
		}

		parent::tearDown();
	}

	/**
	 * Saving the form without changing the day keeps the stored time of day.
	 *
	 * @return void
	 */
	public function test_save_with_unchanged_day_keeps_stored_created_time(): void {
		$created   = gmmktime( 15, 0, 0, 10, 30, 2026 );
		$client_id = $this->create_client( $created );

		$result = $this->save_client( $client_id, array( 'client.created' => gmdate( 'Y-m-d', $created ) ) );

		$this->assertArrayNotHasKey( 'error', (array) $result );
		$this->assertSame( $created, $this->read_created( $client_id ) );
	}

	/**
	 * Picking another day still changes the Added on date.
	 *
	 * @return void
	 */
	public function test_save_with_new_day_updates_created(): void {
		$client_id = $this->create_client( gmmktime( 15, 0, 0, 10, 30, 2026 ) );

		$this->save_client( $client_id, array( 'client.created' => '2026-10-12' ) );

		$this->assertSame( strtotime( '2026-10-12' ), $this->read_created( $client_id ) );
	}

	/**
	 * A blank client field is not stored, a filled one is, and clearing it later empties the stored value.
	 *
	 * @return void
	 */
	public function test_blank_client_fields_are_not_stored_and_clearing_still_works(): void {
		$client_id = $this->create_client( time() );
		$field_a   = $this->create_field( 'MWP2112 Field A' );
		$field_b   = $this->create_field( 'MWP2112 Field B' );

		$this->save_client(
			$client_id,
			array(),
			array(
				$field_a => '',
				$field_b => '',
			)
		);
		$this->assertSame( array(), $this->read_values( $client_id ) );

		$this->save_client(
			$client_id,
			array(),
			array(
				$field_a => 'Gold plan',
				$field_b => '',
			)
		);
		$this->assertSame( array( $field_a => 'Gold plan' ), $this->read_values( $client_id ) );

		$this->save_client(
			$client_id,
			array(),
			array(
				$field_a => '',
				$field_b => '',
			)
		);
		$this->assertSame( array( $field_a => '' ), $this->read_values( $client_id ) );
	}

	/**
	 * A general field stays on a client's form and widgets when only another client has a value for it.
	 *
	 * @return void
	 */
	public function test_general_field_without_a_stored_value_is_still_listed(): void {
		$client_a = $this->create_client( time() );
		$client_b = $this->create_client( time() );
		$field_id = $this->create_field( 'MWP2112 Plan' );

		$this->save_client( $client_a, array(), array( $field_id => 'Gold plan' ) );
		$this->save_client( $client_b, array(), array( $field_id => '' ) );
		$this->assertSame( array(), $this->read_values( $client_b ) );

		$indexed = MainWP_DB_Client::instance()->get_client_fields( true, $client_b, true );
		$this->assertArrayHasKey( 'MWP2112 Plan', $indexed );
		$this->assertSame( '', (string) $indexed['MWP2112 Plan']->field_value );

		$listed = wp_list_pluck( MainWP_DB_Client::instance()->get_client_fields( true, $client_b ), 'field_value', 'field_name' );
		$this->assertArrayHasKey( 'MWP2112 Plan', $listed );
		$this->assertSame( '', (string) $listed['MWP2112 Plan'] );
	}

	/**
	 * Create a client with a given created timestamp.
	 *
	 * @param int $created Created timestamp.
	 * @return int
	 */
	private function create_client( $created ) {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Test Client MWP-2112 ' . wp_generate_password( 8, false ),
				'client_email' => 'test-mwp2112-' . wp_generate_password( 8, false ) . '@example.com',
				'created'      => $created,
				'suspended'    => 0,
			),
			true
		);

		$client_id          = (int) $client->client_id;
		$this->client_ids[] = $client_id;
		return $client_id;
	}

	/**
	 * Create a general client field.
	 *
	 * @param string $name Field name.
	 * @return int
	 */
	private function create_field( $name ) {
		$field = MainWP_DB_Client::instance()->add_client_field(
			array(
				'field_name' => $name,
				'field_desc' => $name,
				'client_id'  => 0,
			)
		);

		$field_id          = (int) $field->field_id;
		$this->field_ids[] = $field_id;
		return $field_id;
	}

	/**
	 * Submit the Edit Client form.
	 *
	 * @param int   $client_id     Client ID.
	 * @param array $extra_fields  Extra default fields.
	 * @param array $custom_values Custom field values keyed by field ID.
	 * @return array|null
	 */
	private function save_client( $client_id, $extra_fields = array(), $custom_values = array() ) {
		$client        = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
		$custom_fields = array();
		foreach ( $custom_values as $field_id => $value ) {
			$custom_fields[] = array( $field_id => $value );
		}

		$_POST = array(
			'nonce_client_id' => wp_create_nonce( 'editing-client-' . $client_id ),
			'client_fields'   => array(
				'client_id'     => $client_id,
				'default_field' => array_merge( array( 'client.name' => $client->name ), $extra_fields ),
				'custom_fields' => $custom_fields,
			),
		);

		ob_start();
		MainWP_Client::add_client();
		return json_decode( ob_get_clean(), true );
	}

	/**
	 * Read the stored created value.
	 *
	 * @param int $client_id Client ID.
	 * @return int
	 */
	private function read_created( $client_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT created FROM ' . $wpdb->prefix . 'mainwp_wp_clients WHERE client_id = %d', $client_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Read stored field values for a client, keyed by field ID.
	 *
	 * @param int $client_id Client ID.
	 * @return array
	 */
	private function read_values( $client_id ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT field_id, field_value FROM ' . $wpdb->prefix . 'mainwp_wp_clients_field_values WHERE value_client_id = %d ORDER BY field_id', $client_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$values = array();
		foreach ( $rows as $row ) {
			$values[ (int) $row->field_id ] = $row->field_value;
		}
		return $values;
	}
}
