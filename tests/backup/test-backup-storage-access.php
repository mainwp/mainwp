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
	 * @param string       $actor Browser role.
	 * @return array Captured response and database evidence.
	 */
	private function call_handler( string $action, $scope, string $actor = 'N' ): array {
		$process = proc_open(
			// nosemgrep: AIK_exec-use -- test harness; the argv array bypasses the shell and every element is a test constant or fixture value.
			array( PHP_BINARY, __DIR__ . '/fixtures/storage-request.php', $action, wp_json_encode( $scope ), $actor ),
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
				foreach ( array( 'N', 'S+' ) as $actor ) {
					$cases[ $action . '-' . $label . '-' . $actor ] = array( $action, $scope, $actor );
				}
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
	 * @param string $actor Browser role.
	 * @return void
	 */
	public function test_restricted_storage_write_refused( string $action, array $scope, string $actor ): void {
		$data = $this->call_handler( $action, $scope, $actor );
		$this->assertSame( array( 'error' => 'Only WordPress administrators can change storage destinations.' ), $data['response'] );
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
		foreach ( array( 'N', 'A' ) as $actor ) {
			foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_default', 'mainwp_storage_delete_confirmed' ) as $action ) {
				$data = $this->call_handler( $action, 'all', $actor );
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
	}

	/** Administrator declines must retire credentials without contacting Dropbox. */
	public function test_administrator_oauth_paths_still_work(): void {
		foreach ( array( 'N', 'A' ) as $actor ) {
			$start = $this->call_handler( 'mainwp_dropbox_oauth_start', 'all', $actor );
			$this->assertArrayHasKey( 'authorize_url', $start['response'] );
			$this->assertSame( $start['before'], $start['after'] );
			$callback = $this->call_handler( 'oauth_callback_declined', 'all', $actor );
			$this->assertSame( array( 'error' => 'The Dropbox authorization was declined. Nothing was saved.' ), $callback['response'] );
			$this->assertSame( $callback['before'], $callback['after'] );
			$this->assertSame( 0, $start['http_calls'] );
			$this->assertSame( 0, $callback['http_calls'] );
			$this->assertTrue( $callback['oauth_state_consumed'] );
			$this->assertTrue( $callback['oauth_key_existed'] );
			$this->assertTrue( $callback['oauth_key_retired'] );
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

	/** OAuth without the storage right must not redirect or save a notice. */
	public function test_oauth_callback_without_right_stays_silent(): void {
		$data = $this->call_handler( 'oauth_callback', array( 17 ), 'S-right' );
		$this->assertNull( $data['response'] );
		$this->assertSame( $data['before'], $data['after'] );
		$this->assertSame( 0, $data['lookups'] );
		$this->assertSame( 0, $data['http_calls'] );
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
			$notice = $xpath->query( '//*[@id="mainwp-storage-message-zone"]/div[@class="ui info visible message"]' );
			$this->assertSame( 'all' === $scope ? 0 : 1, $notice->length );
			if ( 'all' !== $scope ) {
				$this->assertSame( 'Only WordPress administrators can add or change storage destinations. Destinations are shared by every site on this Dashboard.', trim( $notice->item( 0 )->textContent ) );
			}
			$this->assertStringContainsString( 'Visible destination', $html );
		}
	}
}
