<?php
/**
 * MWP-1803 Client Overview suspend permissions.
 *
 * access_client_dashboard may open the overview. It must not expose or
 * perform client suspend and edit actions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client_Overview_Info;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Post_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1803_Client_Suspend_Permissions
 */
class Test_MWP_1803_Client_Suspend_Permissions extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Remove the Team Control stand-in and request data before the next test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_currentusercan' );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Overview-only users do not see Edit Client or Suspend.
	 */
	public function test_access_client_dashboard_user_does_not_see_edit_or_suspend_controls(): void {
		$created = $this->create_client_with_site();
		$this->login_with_dashboard_caps( array( 'access_client_dashboard' ) );

		$html = $this->render_client_info( $created['client_id'] );

		$this->assertStringNotContainsString( 'Edit Client', $html );
		$this->assertStringNotContainsString( 'client-suspend-unsuspend-sites', $html );
		$this->assertStringNotContainsString( 'ClientAddNew', $html );
	}

	/**
	 * manage_clients users still see the mutation controls.
	 */
	public function test_manage_clients_user_sees_edit_and_suspend_controls(): void {
		$created = $this->create_client_with_site();
		$this->login_with_dashboard_caps( array( 'manage_clients' ) );

		$html = $this->render_client_info( $created['client_id'] );

		$this->assertStringContainsString( 'Edit Client', $html );
		$this->assertStringContainsString( 'client-suspend-unsuspend-sites', $html );
		$this->assertStringContainsString( '>Suspend<', $html );
	}

	/**
	 * The suspend handler rejects an overview-only user and leaves sites unchanged.
	 */
	public function test_suspend_handler_rejects_user_without_manage_clients(): void {
		$created   = $this->create_client_with_site();
		$client_id = $created['client_id'];
		$site_id   = $created['site_id'];
		$this->login_with_dashboard_caps( array( 'access_client_dashboard' ) );

		$message = $this->call_suspend_handler( $client_id, 1 );

		$this->assertSame( 'Error - insufficient permissions!', $message );
		$this->assertSame( 0, $this->site_suspended( $site_id ) );
		$this->assertSame( 0, $this->client_suspended( $client_id ) );
	}

	/**
	 * A manage_clients user can still suspend the client's sites.
	 */
	public function test_suspend_handler_allows_manage_clients_user(): void {
		$created   = $this->create_client_with_site();
		$client_id = $created['client_id'];
		$site_id   = $created['site_id'];
		$this->login_with_dashboard_caps( array( 'manage_clients' ) );

		$message = $this->call_suspend_handler( $client_id, 1 );

		$this->assertSame( 'success', $message );
		$this->assertSame( 1, $this->site_suspended( $site_id ) );
		$this->assertSame( 1, $this->client_suspended( $client_id ) );
	}

	/**
	 * Create an active client and one site that belongs to it.
	 *
	 * @return array{client_id: int, site_id: int}
	 */
	private function create_client_with_site(): array {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Overview Client',
				'client_email' => 'overview-' . wp_generate_uuid4() . '@example.com',
				'created'      => time(),
				'suspended'    => 0,
			),
			true
		);

		$this->assertIsObject( $client );

		$client_id = (int) $client->client_id;
		$site_id   = $this->create_test_site(
			array(
				'name'      => 'Overview Client Site',
				'url'       => 'https://overview-' . wp_generate_uuid4() . '.example.com/',
				'client_id' => $client_id,
				'suspended' => 0,
			)
		);

		return array(
			'client_id' => $client_id,
			'site_id'   => $site_id,
		);
	}

	/**
	 * Sign in a dashboard administrator and grant only the given rights.
	 *
	 * Team Control users are WordPress administrators. The extension decides
	 * MainWP rights through mainwp_currentusercan, whose default value is true.
	 *
	 * @param array<int, string> $allowed Dashboard capability slugs to allow.
	 */
	private function login_with_dashboard_caps( array $allowed ): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		add_filter(
			'mainwp_currentusercan',
			static function ( $can, $cap_type, $cap ) use ( $allowed ) {
				unset( $can );
				return 'dashboard' === $cap_type && in_array( $cap, $allowed, true );
			},
			999,
			3
		);
	}

	/**
	 * Render the Client Info widget.
	 *
	 * @param int $client_id Client ID.
	 * @return string Widget markup.
	 */
	private function render_client_info( $client_id ): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		ob_start();
		MainWP_Client_Overview_Info::render_client_overview( $client_id );
		return (string) ob_get_clean();
	}

	/**
	 * Post a suspend request and return the handler's wp_die message.
	 *
	 * @param int $client_id      Client ID.
	 * @param int $suspend_status 1 to suspend, 0 to unsuspend.
	 * @return string
	 */
	private function call_suspend_handler( $client_id, $suspend_status ): string {
		$nonce                = wp_create_nonce( 'mainwp_clients_suspend_client' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['clientid']    = $client_id;
		$_POST['suspend_status'] = $suspend_status;

		try {
			MainWP_Post_Handler::instance()->mainwp_clients_suspend_client();
		} catch ( \WPDieException $e ) {
			return (string) $e->getMessage();
		}

		$this->fail( 'The suspend handler should stop with wp_die().' );
	}

	/**
	 * Read a site's suspended flag.
	 *
	 * @param int $site_id Site ID.
	 * @return int
	 */
	private function site_suspended( $site_id ): int {
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertIsObject( $website );
		return (int) $website->suspended;
	}

	/**
	 * Read a client's suspended flag.
	 *
	 * @param int $client_id Client ID.
	 * @return int
	 */
	private function client_suspended( $client_id ): int {
		$client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
		$this->assertIsObject( $client );
		return (int) $client->suspended;
	}
}
