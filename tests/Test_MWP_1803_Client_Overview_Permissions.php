<?php
/**
 * MWP-1803 Client Overview permission gate.
 *
 * Users without manage_clients must not fatal on a bad namespace, and
 * access_client_dashboard must be able to open the overview.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1803_Client_Overview_Permissions
 */
class Test_MWP_1803_Client_Overview_Permissions extends \WP_UnitTestCase {

	/**
	 * Capability checks recorded by the Team Control stand-in.
	 *
	 * @var array<int, array{type: string, cap: string, granted: bool}>
	 */
	private $checked_caps = array();

	/**
	 * Remove the Team Control stand-in before the next test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'mainwp_currentusercan' );
		parent::tearDown();
	}

	/**
	 * A user with only access_client_dashboard reaches the overview.
	 *
	 * manage_clients is denied, so the second check must run. The old
	 * \mainw\ call fataled here and never evaluated access_client_dashboard.
	 */
	public function test_user_with_only_access_client_dashboard_reaches_overview(): void {
		$this->login_with_dashboard_caps( array( 'access_client_dashboard' ) );

		$html  = '';
		$error = null;

		$this->render_client_overview( $html, $error );

		$this->assertNull( $error, $error instanceof \Throwable ? $error->getMessage() : '' );
		$this->assertSame(
			array(
				array(
					'type'    => 'dashboard',
					'cap'     => 'manage_clients',
					'granted' => false,
				),
				array(
					'type'    => 'dashboard',
					'cap'     => 'access_client_dashboard',
					'granted' => true,
				),
			),
			array_slice( $this->checked_caps, 0, 2 )
		);
		$this->assertStringNotContainsString( 'mainwp-permission-error', $html );
		$this->assertStringContainsString( 'mainwp-grid-wrapper', $html );
	}

	/**
	 * A user with neither permission gets the permission notice and stops.
	 */
	public function test_user_with_neither_permission_sees_permission_notice(): void {
		$this->login_with_dashboard_caps( array() );

		$html  = '';
		$error = null;

		$this->render_client_overview( $html, $error );

		$this->assertNull( $error, $error instanceof \Throwable ? $error->getMessage() : '' );
		$this->assertSame(
			array(
				array(
					'type'    => 'dashboard',
					'cap'     => 'manage_clients',
					'granted' => false,
				),
				array(
					'type'    => 'dashboard',
					'cap'     => 'access_client_dashboard',
					'granted' => false,
				),
			),
			$this->checked_caps
		);
		$this->assertStringContainsString( 'mainwp-permission-error', $html );
		$this->assertStringContainsString( 'Client Dashboard', $html );
		$this->assertStringNotContainsString( 'mainwp-grid-wrapper', $html );
	}

	/**
	 * Sign in a non-admin and grant only the given dashboard rights.
	 *
	 * MainWP Team Control decides these rights through mainwp_currentusercan.
	 * The default filter value is true, so this callback replaces it.
	 *
	 * @param array<int, string> $allowed Dashboard capability slugs to allow.
	 */
	private function login_with_dashboard_caps( array $allowed ): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );

		$this->checked_caps = array();
		$checked            = &$this->checked_caps;

		add_filter(
			'mainwp_currentusercan',
			static function ( $can, $cap_type, $cap ) use ( &$checked, $allowed ) {
				unset( $can );
				$granted   = ( 'dashboard' === $cap_type && in_array( $cap, $allowed, true ) );
				$checked[] = array(
					'type'    => $cap_type,
					'cap'     => $cap,
					'granted' => $granted,
				);
				return $granted;
			},
			999,
			3
		);
	}

	/**
	 * Render Client Overview and capture output and throwables.
	 *
	 * @param string          $html  Captured markup.
	 * @param \Throwable|null $error Error thrown while rendering, if any.
	 */
	private function render_client_overview( &$html, &$error ): void {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		// A non-MainWP screen id still satisfies $screen->id in the overview body.
		// A mainwp_* screen runs current_screen redirects during tests.
		set_current_screen( 'dashboard' );

		$level = ob_get_level();
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Client_Overview::instance()->on_show_page();
		} catch ( \Throwable $thrown ) {
			$error = $thrown;
		} finally {
			$html = '';
			while ( ob_get_level() > $level ) {
				$html = ob_get_clean() . $html;
			}
		}
	}
}
