<?php
/**
 * Add-on update batch pacing regression coverage.
 *
 * @package MainWP/Dashboard
 */

namespace MWP2013\Tests;

use MainWP\Dashboard\MainWP_API_Handler;
use MainWP\Dashboard\MainWP_Extensions_Handler;

require_once __DIR__ . '/fixtures/mwp2013-functions.php';

/** WordPress HTTP integration coverage for Add-on batch pacing. */
class Test_MWP_2013_Addon_Update_Latency extends \WP_UnitTestCase {
	/**
	 * Extensions before this fixture.
	 *
	 * @var array|null
	 */
	private $saved_extensions;
	/**
	 * Installed plugin cache before this fixture.
	 *
	 * @var mixed
	 */
	private $saved_plugins;
	/**
	 * Sent Add-on batches.
	 *
	 * @var array
	 */
	private $batches = array();

	/** Prepare transport and pause observation. */
	public function set_up() {
		parent::set_up();
		$this->saved_extensions    = MainWP_Extensions_Handler::$extensions;
		$this->saved_plugins       = wp_cache_get( 'plugins', 'plugins' );
		$this->batches             = array();
		$GLOBALS['mwp2013_pauses'] = array();
		$GLOBALS['mwp2013_events'] = array();
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}

	/** Restore request hooks, globals and extension state. */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		MainWP_Extensions_Handler::$extensions = $this->saved_extensions;
		if ( false === $this->saved_plugins ) {
			wp_cache_delete( 'plugins', 'plugins' );
		} else {
			wp_cache_set( 'plugins', $this->saved_plugins, 'plugins' );
		}
		unset( $GLOBALS['mwp2013_pauses'] );
		unset( $GLOBALS['mwp2013_events'] );
		parent::tear_down();
	}

	/**
	 * Capture the real bulk request without network access.
	 *
	 * @param mixed  $pre Preempted response.
	 * @param array  $args Transport arguments.
	 * @param string $url Request URL.
	 * @return array|\WP_Error Canned transport response.
	 */
	public function http( $pre, $args, $url ) {
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( ! isset( $query['request'] ) || 'bulkupdatecheck' !== $query['request'] ) {
			return new \WP_Error( 'fixture_network_blocked', 'Unexpected fixture transport' );
		}
		$batch                       = json_decode( base64_decode( $query['extensions'] ), true );
		$this->batches[]             = $batch;
		$GLOBALS['mwp2013_events'][] = 'http';
		$result                      = array();
		foreach ( $batch as $slug => $extension ) {
			$result[ $slug ] = array(
				'new_version' => '2.0.0',
				'package'     => 'https://example.org/' . $slug . '.zip',
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $result ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Seed activated Add-ons.
	 *
	 * @param int $count Add-on count.
	 * @return array Extension records.
	 */
	private function activate( $count ) {
		$extensions = array();
		for ( $i = 1; $i <= $count; ++$i ) {
			$slug         = 'mwp2013-addon-' . $i;
			$extensions[] = array(
				'slug'             => $slug . '/' . $slug . '.php',
				'api'              => $slug,
				'version'          => '1.0.0',
				'product_id'       => 1000 + $i,
				'instance_id'      => 'fixture-instance-' . $i,
				'software_version' => '1.0.0',
				'activated_key'    => 'Activated',
				'apiManager'       => true,
				'name'             => 'Fixture ' . $i,
				'page'             => 'Fixture' . $i,
			);
		}
		update_option( 'mainwp_extensions', $extensions );
		MainWP_Extensions_Handler::$extensions = $extensions;
		$plugins                               = array();
		foreach ( $extensions as $extension ) {
			$plugins[ $extension['slug'] ] = array(
				'Name'      => $extension['name'],
				'Version'   => $extension['version'],
				'UpdateURI' => '',
			);
		}
		wp_cache_set( 'plugins', array( '' => $plugins ), 'plugins' );
		return $extensions;
	}

	/**
	 * Batch-boundary cases.
	 *
	 * @return array Test cases.
	 */
	public static function batch_sizes() {
		return array(
			'one'      => array( 1, 0, array( 'http' ) ),
			'six'      => array( 6, 0, array( 'http' ) ),
			'seven'    => array( 7, 1, array( 'http', 'sleep', 'http' ) ),
			'twelve'   => array( 12, 1, array( 'http', 'sleep', 'http' ) ),
			'thirteen' => array( 13, 2, array( 'http', 'sleep', 'http', 'sleep', 'http' ) ),
		);
	}

	/**
	 * Check batch boundaries and request contents.
	 *
	 * @param int   $count Add-on count.
	 * @param int   $pauses Expected pauses.
	 * @param array $events Expected transport and pause order.
	 * @dataProvider batch_sizes
	 */
	public function test_batches_preserve_payload_order_and_only_pause_between( $count, $pauses, $events ) {
		$extensions = $this->activate( $count );
		$expected   = array();
		foreach ( $extensions as $extension ) {
			$expected[ $extension['api'] ] = array(
				'plugin_name'      => $extension['api'],
				'version'          => '1.0.0',
				'product_id'       => $extension['product_id'],
				'api_key'          => '',
				'instance'         => $extension['instance_id'],
				'software_version' => '1.0.0',
			);
		}
		$result = MainWP_API_Handler::check_exts_upgrade();
		$this->assertSame( array_chunk( $expected, 6, true ), $this->batches );
		$this->assertSame( array_keys( $expected ), array_keys( $result ) );
		$this->assertSame( array_fill( 0, $pauses, 3 ), $GLOBALS['mwp2013_pauses'] );
		$this->assertSame( $events, $GLOBALS['mwp2013_events'] );
	}
}
