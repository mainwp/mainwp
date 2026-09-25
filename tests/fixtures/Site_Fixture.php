<?php
/**
 * MainWP site test fixtures.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

trait Site_Fixture {

	/**
	 * Create a test site.
	 *
	 * @param array $args Site properties.
	 *
	 * @return int Site ID.
	 */
	protected function create_test_site( $args = array() ) {
		global $wpdb;

		$verify_method = $args['verify_method'] ?? 1;
		$version       = $args['version'] ?? '5.0.0';
		$sync_errors   = $args['sync_errors'] ?? '';

		unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

		$defaults = array(
			'userid'               => max( 1, get_current_user_id() ),
			'url'                  => 'https://test-uptime-' . wp_generate_uuid4() . '.example.com/',
			'name'                 => 'Test Site',
			'adminname'            => 'admin',
			'pubkey'               => 'test-pubkey',
			'privkey'              => 'test-privkey',
			'ssl_version'          => 0,
			'http_user'            => '',
			'http_pass'            => '',
			'suspended'            => 0,
			'offline_check_result' => 1,
			'client_id'            => 0,
		);

		$formats = array(
			'userid'               => '%d',
			'url'                  => '%s',
			'name'                 => '%s',
			'adminname'            => '%s',
			'pubkey'               => '%s',
			'privkey'              => '%s',
			'ssl_version'          => '%d',
			'http_user'            => '%s',
			'http_pass'            => '%s',
			'suspended'            => '%d',
			'offline_check_result' => '%d',
			'client_id'            => '%d',
		);

		$data         = array_merge( $defaults, $args );
		$format_array = array();

		foreach ( array_keys( $data ) as $key ) {
			$format_array[] = $formats[ $key ] ?? '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			$data,
			$format_array
		);

		$site_id = (int) $wpdb->insert_id;

		$this->set_site_option( $site_id, 'verify_method', $verify_method );

		$this->create_test_site_sync(
			$site_id,
			array(
				'version'     => $version,
				'sync_errors' => $sync_errors,
			)
		);

		return $site_id;
	}

	/**
	 * Set a MainWP site option.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $option  Option name.
	 * @param mixed  $value   Option value.
	 *
	 * @return void
	 */
	protected function set_site_option( $site_id, $option, $value ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_options',
			array(
				'wpid'  => $site_id,
				'name'  => $option,
				'value' => is_scalar( $value ) ? $value : maybe_serialize( $value ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Create a MainWP site sync record.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Sync properties.
	 *
	 * @return void
	 */
	protected function create_test_site_sync( $site_id, $args = array() ) {
		global $wpdb;

		$data = array_merge(
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			),
			$args
		);

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			$data,
			array( '%d', '%s', '%s' )
		);
	}
}