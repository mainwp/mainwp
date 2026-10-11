<?php
/**
 * Dashboard-wide storage must remain read-only for site-scoped accounts.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_View;

/** Class MainWP_Backup_Storage_Access_Test. */
class MainWP_Backup_Storage_Access_Test extends \WP_UnitTestCase {
	/**
	 * The real exits must not terminate PHPUnit or commit fixture rows.
	 *
	 * @param string       $action Handler action.
	 * @param array|string $scope Allowed site scope.
	 * @return array Captured response and database evidence.
	 */
	private function call_handler( string $action, $scope ): array {
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/storage-request.php', $action, wp_json_encode( $scope ) ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$body   = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $errors );
		$data = json_decode( $body, true );
		$this->assertIsArray( $data, $body . $errors );
		return $data;
	}

	/**
	 * Both an empty allowlist and a partial one require refusal.
	 *
	 * @return array Handler and scope cases.
	 */
	public static function restricted_writes(): array {
		$cases = array();
		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_default', 'mainwp_storage_delete', 'mainwp_storage_delete_confirmed', 'mainwp_dropbox_oauth_start', 'oauth_callback' ) as $action ) {
			foreach ( array(
				'empty'   => array(),
				'partial' => array( 17 ),
			) as $label => $scope ) {
				$cases[ $action . '-' . $label ] = array( $action, $scope );
			}
		}
		return $cases;
	}

	/**
	 * Refusal must precede credential reads, task-name previews and provider calls.
	 *
	 * @dataProvider restricted_writes
	 * @param string $action Handler action.
	 * @param array  $scope Allowed site ids.
	 * @return void
	 */
	public function test_restricted_storage_write_refused( string $action, array $scope ): void {
		$data = $this->call_handler( $action, $scope );
		$this->assertSame( array( 'error' => 'Storage destinations are shared by the whole Dashboard. Accounts limited to some sites cannot change them.' ), $data['response'] );
		$this->assertSame( $data['before'], $data['after'] );
		$this->assertSame( 0, $data['lookups'] );
		$this->assertSame( 0, $data['http_calls'] );
	}

	/**
	 * Global administrators retain the existing storage workflow.
	 *
	 * @return void
	 */
	public function test_unrestricted_storage_writes_still_work(): void {
		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_default', 'mainwp_storage_delete_confirmed' ) as $action ) {
			$data = $this->call_handler( $action, 'all' );
			$this->assertEmpty( $data['response']['error'] ?? null, $action );
			if ( 'mainwp_storage_save' === $action ) {
				$this->assertSame( 'Changed destination', $data['after']['row']['name'] );
			} elseif ( 'mainwp_storage_test' === $action ) {
				$this->assertTrue( $data['response']['ok'] );
				$this->assertSame( $data['before'], $data['after'] );
			} elseif ( 'mainwp_storage_default' === $action ) {
				$this->assertSame( '1', $data['after']['row']['is_default'] );
			} else {
				$this->assertTrue( $data['response']['deleted'] );
				$this->assertNull( $data['after']['row'] );
			}
		}
	}

	/**
	 * Restricted readers may still load the edit-form metadata without secrets.
	 *
	 * @return void
	 */
	public function test_restricted_storage_get_still_works(): void {
		foreach ( array( array(), array( 17 ) ) as $scope ) {
			$data = $this->call_handler( 'mainwp_storage_get', $scope );
			$this->assertSame( 'Shared destination', $data['response']['name'] );
			$this->assertArrayNotHasKey( 'password', $data['response']['settings'] );
			$this->assertSame( $data['before'], $data['after'] );
		}
	}

	/**
	 * DOM checks distinguish usable controls from script selectors.
	 *
	 * @return void
	 */
	public function test_storage_page_respects_site_restriction(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store = MainWP_Backup_Storage_Destinations::instance();
		$id    = $store->add_destination(
			array(
				'name'     => 'Visible destination',
				'provider' => 'local',
				'settings' => array(),
			)
		);
		$this->assertGreaterThan( 0, $id );
		foreach ( array( 'all', array(), array( 17 ) ) as $scope ) {
			$filter = static function () use ( $scope ) {
				return $scope;
			};
			add_filter( 'mainwp_currentuserallowedaccesssites', $filter );
			ob_start();
			try {
				MainWP_Backup_Storage_View::render();
			} finally {
				$html = ob_get_clean();
				remove_filter( 'mainwp_currentuserallowedaccesssites', $filter );
			}
			$dom = new \DOMDocument();
			@$dom->loadHTML( $html );
			$xpath = new \DOMXPath( $dom );
			foreach ( array( 'edit', 'test', 'make-default', 'delete' ) as $control ) {
				$this->assertSame( 'all' === $scope ? 1 : 0, $xpath->query( '//*[self::button or self::a][contains(concat(" ", @class, " "), " mainwp-storage-' . $control . ' ")]' )->length, $control );
			}
			foreach ( array( 'mainwp-storage-form', 'mainwp-storage-add-new' ) as $control ) {
				$this->assertSame( 'all' === $scope ? 1 : 0, $xpath->query( '//*[@id="' . $control . '"]' )->length );
			}
			$notice = $xpath->query( '//*[@id="mainwp-storage-message-zone"]/div[@class="ui message"]' );
			$this->assertSame( 'all' === $scope ? 0 : 1, $notice->length );
			if ( 'all' !== $scope ) {
				$this->assertSame( 'Storage destinations are shared by the whole Dashboard, so accounts limited to some sites can view them but not change them.', trim( $notice->item( 0 )->textContent ) );
			}
			$this->assertStringContainsString( 'Visible destination', $html );
		}
	}
}
