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
		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_test', 'mainwp_storage_default', 'mainwp_storage_delete', 'mainwp_storage_delete_confirmed', 'mainwp_dropbox_oauth_start', 'oauth_callback', 'mainwp_pcloud_connect' ) as $action ) {
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
	 * The edit form is unavailable to site-scoped accounts.
	 *
	 * @return void
	 */
	public function test_restricted_storage_get_refused(): void {
		foreach ( array( array(), array( 17 ) ) as $scope ) {
			$data = $this->call_handler( 'mainwp_storage_get', $scope );
			$this->assertSame( array( 'error' => 'Only WordPress administrators can change storage destinations.' ), $data['response'] );
			$this->assertArrayNotHasKey( 'settings', $data['response'] );
			$this->assertSame( $data['before'], $data['after'] );
			$this->assertSame( 0, $data['lookups'] );
		}
	}

	/** Unrestricted staff still need non-secret settings to edit a destination. */
	public function test_unrestricted_storage_get_still_works(): void {
		$data = $this->call_handler( 'mainwp_storage_get', 'all' );
		$this->assertSame( array( 'id', 'name', 'provider', 'is_default', 'settings', 'credentials_invalid' ), array_keys( $data['response'] ) );
		$this->assertSame( 'Shared destination', $data['response']['name'] );
		$this->assertSame( 'local', $data['response']['provider'] );
		$this->assertNotEmpty( $data['response']['settings']['base_path'] );
		foreach ( \MainWP\Dashboard\MainWP_Backup_Storage_Handler::SECRET_FIELDS as $field ) {
			$this->assertArrayNotHasKey( $field, $data['response']['settings'] );
		}
		$this->assertSame( $data['before'], $data['after'] );
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
				$this->assertSame( 'all' === $scope ? ( 'edit' === $control ? 2 : 1 ) : 0, $xpath->query( '//*[self::button or self::a][contains(concat(" ", @class, " "), " mainwp-storage-' . $control . ' ")]' )->length, $control );
			}
			foreach ( array( 'mainwp-storage-form', 'mainwp-storage-add-new' ) as $control ) {
				$this->assertSame( 'all' === $scope ? 1 : 0, $xpath->query( '//*[@id="' . $control . '"]' )->length );
			}
			$notice = $xpath->query( '//*[@id="mainwp-storage-message-zone"]/div[@class="ui blue visible message"]' );
			$this->assertSame( 'all' === $scope ? 0 : 1, $notice->length );
			if ( 'all' !== $scope ) {
				$this->assertSame( 'Only WordPress administrators can add or change storage destinations. Destinations are shared by every site on this Dashboard.', trim( $notice->item( 0 )->textContent ) );
			}
			$this->assertStringContainsString( 'Visible destination', $html );
		}
	}
	/** Storage redirection grants access to archive bytes. */
	public function test_storage_requires_download_right(): void {
		foreach ( array( 'mainwp_storage_save', 'mainwp_storage_default', 'mainwp_storage_test', 'mainwp_storage_delete', 'mainwp_storage_get', 'mainwp_dropbox_oauth_start', 'oauth_callback', 'mainwp_pcloud_connect' ) as $action ) {
			$data = $this->call_handler( $action, 'all', 'S+' );
			$this->assertSame( array( 'error' => 'Only WordPress administrators can change storage destinations.' ), $data['response'], $action );
			$this->assertSame( $data['before'], $data['after'], $action );
			$this->assertSame( 0, $data['lookups'], $action );
			$this->assertSame( 0, $data['http_calls'], $action );
		}
	}

	/** The list remains useful when archive access is withheld. */
	public function test_storage_page_without_download_right_is_read_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$deny = static function ( $allowed, $type, $cap ) {
			return 'dashboard' === $type && 'download_backups' === $cap ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		ob_start();
		try {
			MainWP_Backup_Storage_View::render();
		} finally {
			$html = ob_get_clean();
			remove_filter( 'mainwp_currentusercan', $deny );
		}
		$this->assertStringContainsString( 'Only WordPress administrators can add or change storage destinations.', $html );
		$this->assertStringNotContainsString( 'id="mainwp-storage-form"', $html );
		$this->assertStringNotContainsString( 'let storageModal', $html );
	}
	/** Connect pCloud dispatches through the registered action and saves only the server-side exchange result. */
	public function test_pcloud_connect_dispatch_saves_server_owned_settings(): void {
		foreach ( array( 'N', 'A' ) as $actor ) {
			$data = $this->call_handler( 'mainwp_pcloud_connect', 'all', $actor );
			$this->assertSame( array( 'id' ), array_keys( $data['response'] ), wp_json_encode( $data['response'] ) );
			$this->assertSame( 'fixture-client', $data['stored_settings']['client_id'] );
			$this->assertSame( 'fixture-access-token', $data['stored_settings']['access_token'] );
			$this->assertSame( 1, $data['stored_settings']['locationid'] );
			$this->assertSame( 'owner@example.com', $data['stored_settings']['account_email'] );
			$this->assertSame( 'Archive', $data['stored_settings']['base_path'] );
			$this->assertSame( '101', $data['stored_settings']['_mainwp_retention_identity']['userid'] );
			$this->assertArrayNotHasKey( 'client_secret', $data['stored_settings'] );
			$this->assertArrayNotHasKey( 'authorization_code', $data['stored_settings'] );
			$this->assertSame(
				array(
					array(
						'url'  => 'https://api.pcloud.com/oauth2_token',
						'body' => array(
							'client_id'     => 'fixture-client',
							'client_secret' => 'fixture-secret', // gitleaks:allow -- test fixture value, not a credential.
							'code'          => 'fixture-paste-code',
						),
					),
				),
				$data['exchanges']
			);
			$this->assertSame( 0, $data['code_copies'] );
		}
	}

	/** A region change needs confirmation before replacing the stored US connection. */
	public function test_pcloud_connect_dispatch_reconnects_in_eu_region(): void {
		$data = $this->call_handler( 'mainwp_pcloud_connect_eu', 'all', 'A' );
		$this->assertArrayHasKey( 'stash_key', $data['pcloud_confirmation'] );
		$this->assertTrue( $data['pcloud_pending_row_unchanged'] );
		$this->assertTrue( $data['pcloud_stash_posted'] );
		$this->assertTrue( $data['pcloud_stash_consumed'] );
		$this->assertSame( $data['before']['row']['id'], (string) $data['response']['id'] );
		$this->assertSame( 2, $data['stored_settings']['locationid'] );
		$this->assertSame( 'eu-owner@example.com', $data['stored_settings']['account_email'] );
		$this->assertSame( 'fixture-client', $data['stored_settings']['client_id'] );
		$this->assertSame( 'fixture-access-token', $data['stored_settings']['access_token'] );
		$this->assertSame( array( 'https://api.pcloud.com/oauth2_token', 'https://eapi.pcloud.com/oauth2_token' ), array_column( $data['exchanges'], 'url' ) );
		$this->assertSame( 0, $data['code_copies'] );
	}

	/** A reconnect without a valid email must not retain the previous account's email. */
	public function test_pcloud_connect_dispatch_reconnect_without_email_clears_old_email(): void {
		foreach ( array( 'mainwp_pcloud_connect_eu_no_email', 'mainwp_pcloud_connect_eu_invalid_email' ) as $action ) {
			$data = $this->call_handler( $action, 'all', 'A' );
			$this->assertArrayHasKey( 'stash_key', $data['pcloud_confirmation'] );
			$this->assertTrue( $data['pcloud_pending_row_unchanged'] );
			$this->assertTrue( $data['pcloud_stash_posted'] );
			$this->assertTrue( $data['pcloud_stash_consumed'] );
			$this->assertSame( $data['before']['row']['id'], (string) $data['response']['id'] );
			$this->assertSame( 2, $data['stored_settings']['locationid'] );
			$this->assertSame( 'fixture-access-token', $data['stored_settings']['access_token'] );
			$this->assertArrayNotHasKey( 'account_email', $data['stored_settings'] );
		}
	}

	/**
	 * wp-config overrides apply only when both values are non-empty.
	 *
	 * @return array Fixture case and whether the constants replace the built-in app.
	 */
	public static function wp_config_credentials(): array {
		return array(
			'both set'     => array( 'pcloud_app_both', true ),
			'secret empty' => array( 'pcloud_app_secret_empty', false ),
			'id only'      => array( 'pcloud_app_id_only', false ),
		);
	}

	/**
	 * Constants persist for the whole process, so each case runs in its own.
	 *
	 * @dataProvider wp_config_credentials
	 * @param string $action Fixture case.
	 * @param bool   $overrides Whether the constants replace the built-in app.
	 */
	public function test_pcloud_app_credentials_prefer_wp_config( string $action, bool $overrides ): void {
		$data     = $this->call_handler( $action, 'all', 'A' )['response'];
		$built_in = array(
			'client_id'     => \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::CLIENT_ID,
			'client_secret' => \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::CLIENT_SECRET,
		);
		$this->assertSame(
			$overrides ? array(
				'client_id'     => 'config-client',
				'client_secret' => 'config-secret', // gitleaks:allow -- test fixture value, not a credential.
			) : $built_in,
			$data['credentials']
		);
		$this->assertFalse( $data['form_secret'] );
		$this->assertSame( 'a', $data['link']['tag'] );
		$this->assertSame( '_blank', $data['link']['target'] );
		$this->assertStringContainsString( 'noopener', $data['link']['rel'] );
		$this->assertSame( $data['authorize'], $data['link']['href'] );
		$this->assertStringStartsWith( 'https://my.pcloud.com/oauth2/authorize?', $data['link']['href'] );
		parse_str( (string) wp_parse_url( $data['link']['href'], PHP_URL_QUERY ), $params );
		$this->assertSame(
			array(
				'client_id'     => $overrides ? 'config-client' : $built_in['client_id'],
				'response_type' => 'code',
			),
			$params
		);
	}

	/** Partial wp-config credentials fall back to the built-in app. */
	public function test_pcloud_connect_partial_wp_config_is_not_used(): void {
		$data = $this->call_handler( 'mainwp_pcloud_connect_partial_config', 'all', 'A' );
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::CLIENT_ID, $data['exchanges'][0]['body']['client_id'] );
	}

	/** Browser saves cannot select a region, including on an existing EU row. */
	public function test_pcloud_crafted_ajax_save_cannot_set_region(): void {
		$new = $this->call_handler( 'pcloud_crafted_save_new', 'all', 'A' );
		$this->assertArrayHasKey( 'error', $new['response'] );
		$this->assertSame( $new['before'], $new['after'] );
		$this->assertSame( 0, $new['http_calls'] );
		$existing = $this->call_handler( 'pcloud_crafted_save_existing', 'all', 'A' );
		$this->assertArrayHasKey( 'id', $existing['response'] );
		$this->assertSame( 2, $existing['stored_settings']['locationid'] );
		$this->assertSame( 0, $existing['http_calls'] );
	}

	/** Connecting still requires the storage management right. */
	public function test_pcloud_connect_without_right_is_refused(): void {
		$data = $this->call_handler( 'mainwp_pcloud_connect', 'all', 'S-right' );
		$this->assertSame( array( 'error' => 'Insufficient permissions to manage backup storage.' ), $data['response'] );
		$this->assertSame( $data['before'], $data['after'] );
		$this->assertSame( 0, $data['lookups'] );
		$this->assertSame( 0, $data['http_calls'] );
	}

	/** A nonce minted for another user cannot drive this user's connection. */
	public function test_pcloud_connect_with_another_users_nonce_is_refused(): void {
		$data = $this->call_handler( 'mainwp_pcloud_connect_foreign_nonce', 'all', 'A' );
		$this->assertStringStartsWith( 'Insecure request!', $data['response']['error'] );
		$this->assertSame( $data['before'], $data['after'] );
		$this->assertSame( 0, $data['http_calls'] );
	}
}
