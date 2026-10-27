<?php
/**
 * Registration policies through handlers, storage, and the cURL transport.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_View as View;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler as Handler;
use MainWP\Dashboard\MainWP_Manage_Sites as Page;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Connect;

/** Verifies TLS policy across registration entry paths. */
class Test_SEC39_TLS extends \WP_UnitTestCase {
	/**
	 * TLS policy passed by the real handler.
	 *
	 * @var int|bool
	 */
	private $captured;
	/**
	 * Request state restored after each test.
	 *
	 * @var array
	 */
	private $request_globals;

	/**
	 * Prepare an administrator and guarded Child response fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request_globals = array( $_POST, $_GET, $_REQUEST, $_SERVER, $GLOBALS['wp']->query_vars );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		add_filter(
			'mainwp_fetch_url_not_authed_pre',
			function ( $pre, $url, $admin, $what, $params, $force, $policy ) {
				$this->captured = $policy;
				return array(
					'register'  => 'OK',
					'wpversion' => '6.8',
				);
			},
			10,
			7
		);
		add_filter( 'mainwp_fetch_url_authed_pre', '__return_empty_array' );
	}

	/**
	 * Restore request globals and REST singletons.
	 */
	public function tearDown(): void {
		list( $_POST, $_GET, $_REQUEST, $_SERVER, $GLOBALS['wp']->query_vars ) = $this->request_globals;
		\MainWP_REST_Authentication::$instance                                 = null;
		$GLOBALS['wp_rest_server'] = null;
		parent::tearDown();
	}

	/**
	 * Run a standalone transport or upload fixture.
	 *
	 * @param string $file Fixture filename.
	 * @param array  $data Input serialized over stdin.
	 * @return array Decoded fixture result.
	 */
	private function fixture( $file, $data ) {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/' . $file ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		fwrite( $pipes[0], json_encode( $data ) );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $err );
		$result = json_decode( $out, true );
		$this->assertIsArray( $result, $out . $err );
		return $result;
	}

	/**
	 * Capture options from the real cURL transport.
	 *
	 * @param mixed $policy Per-site policy.
	 * @param mixed $global_setting Global option, or false when missing.
	 * @param array $extra Additional fixture controls.
	 * @return array Transport options and optional certificate result.
	 */
	private function transport( $policy, $global_setting, $extra = array() ) {
		return $this->fixture(
			'sec39-transport.inc',
			array_merge(
				array(
					'policy' => $policy,
					'global' => $global_setting,
				),
				$extra
			)
		);
	}

	/**
	 * Set the global option or remove it to exercise the default.
	 *
	 * @param mixed $global_setting Global option, or false when missing.
	 */
	private function global_policy( $global_setting ) {
		if ( false === $global_setting ) {
			delete_option( 'mainwp_sslVerifyCertificate' ); } else {
			update_option( 'mainwp_sslVerifyCertificate', $global_setting );
			}
	}

	/**
	 * Create a site through its real handler or registered route.
	 *
	 * @param string $path Registration entry path.
	 * @param array  $fields Request fields.
	 * @return object Saved site row.
	 */
	private function register( $path, $fields ) {
		$url    = 'https://sec39-' . wp_generate_uuid4() . '.example.com/';
		$params = array(
			'url'     => $url,
			'name'    => 'TLS fixture',
			'wpadmin' => 'admin',
		);
		if ( 'v1' === $path ) {
			$result = Handler::rest_api_add_site(
				array_merge(
					array(
						'url'   => $url,
						'name'  => 'TLS fixture',
						'admin' => 'admin',
					),
					$fields
				)
			);
			$this->assertArrayNotHasKey( 'error', $result );
		} elseif ( 'rest' === $path ) {
			global $wpdb;
			$key    = 'ck_' . bin2hex( random_bytes( 16 ) );
			$secret = 'cs_' . bin2hex( random_bytes( 16 ) );
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_api_keys',
				array(
					'user_id'         => get_current_user_id(),
					'description'     => 'TLS fixture',
					'permissions'     => 'read_write',
					'consumer_key'    => mainwp_api_hash( $key ),
					'consumer_secret' => wp_hash_password( $secret ),
					'truncated_key'   => substr( $key, -7 ),
					'enabled'         => 1,
					'last_access'     => current_time( 'mysql' ),
				)
			);
			$_GET['consumer_key']                    = $key;
			$_GET['consumer_secret']                 = $secret;
			$_SERVER['HTTPS']                        = 'on';
			$_SERVER['REQUEST_URI']                  = '/wp-json/mainwp/v2/sites/add';
			$GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites/add';
			\MainWP_REST_Authentication::$instance   = null;
			$this->assertSame( get_current_user_id(), (int) \MainWP_REST_Authentication::get_instance()->authenticate( 0 ), 'Fixture API key must authenticate.' );
			$controller = new \MainWP_Rest_Sites_Controller();
			add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
			$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
			do_action( 'rest_api_init' );
			$request = new \WP_REST_Request( 'POST', '/mainwp/v2/sites/add' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body(
				wp_json_encode(
					array_merge(
						array(
							'url'   => $url,
							'name'  => 'TLS fixture',
							'admin' => 'admin',
						),
						$fields
					)
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( 1, $response->get_data()['success'], wp_json_encode( $response->get_data() ) );
		} elseif ( 'abilities' === $path || 'abilities-adapter' === $path ) {
			$input = array_merge(
				array(
					'url'            => $url,
					'name'           => 'TLS fixture',
					'admin_username' => 'admin',
				),
				$fields
			);
			if ( 'abilities-adapter' === $path ) {
				$result = \MainWP\Dashboard\MainWP_Abilities_Sites::execute_add_site( $input );
				$this->assertNotWPError( $result );
			} else {
				if ( ! wp_get_ability( 'mainwp/add-site-v1' ) ) {
					\MainWP\Dashboard\MainWP_Abilities::init();
					do_action( 'wp_abilities_api_categories_init' );
					do_action( 'wp_abilities_api_init' );
				}
				$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
				do_action( 'rest_api_init' );
				$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/add-site-v1/run' );
				$request->set_header( 'content-type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
				$response = rest_do_request( $request );
				$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			}
		} elseif ( 'hook' === $path ) {
			$this->assertNotFalse( has_filter( 'mainwp_addsite' ) );
			$result = apply_filters( 'mainwp_addsite', array_merge( $params, $fields ) );
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertNotEmpty( $result['siteid'] );
		} elseif ( 'direct' === $path ) {
			$result = View::add_wp_site( false, array_merge( $params, $fields ) );
			$this->assertSame( '', $result[1] );
		} else {
			$_POST  = array_merge(
				array(
					'managesites_add_wpurl'   => $url,
					'managesites_add_wpname'  => 'TLS fixture',
					'managesites_add_wpadmin' => 'admin',
				),
				$fields
			);
			$result = View::add_site();
			$this->assertSame( '', $result[1] );
		}
		$site = MainWP_DB::instance()->get_websites_by_url( $url );
		$site = is_array( $site ) ? reset( $site ) : $site;
		$this->assertIsObject( $site );
		return $site;
	}

	/**
	 * Registration choices under each global setting.
	 *
	 * @return array
	 */
	public static function policies() {
		$cases = array();
		foreach ( array( false, 1, 0 ) as $global_setting ) {
			foreach ( array( 'ui', 'v1', 'direct', 'hook', 'import' ) as $path ) {
				foreach ( array( 'omitted', 'on', 'off', 'inherit' ) as $choice ) {
					$policy = 'on' === $choice ? 1 : ( 'off' === $choice ? 0 : 2 );
					if ( 'ui' === $path && 1 === $policy ) {
						$policy = 2;
					}
					$field  = 'import' === $path ? 'verify_certificate' : 'ssl_verify';
					$fields = 'omitted' === $choice ? array() : array( $field => 'inherit' === $choice ? 2 : ( 'on' === $choice ? 1 : 0 ) );
					if ( 'import' === $path ) {
						$fields['bulk'] = true;
					}
					$cases[ $path . '-' . $choice . '-global-' . ( false === $global_setting ? 'missing' : (string) $global_setting ) ] = array( $path, $fields, $global_setting, $policy );
				}
			}
		}
		return $cases;
	}

	/**
	 * Each entry path keeps registration and first sync on the saved policy.
	 *
	 * @dataProvider policies
	 * @param string $path Registration entry path.
	 * @param array  $fields Request fields.
	 * @param mixed  $global_setting Global TLS option, or false when missing.
	 * @param int    $expected Stored policy.
	 */
	public function test_registration_transport_and_saved_policy( $path, $fields, $global_setting, $expected ) {
		$this->assert_registration_policy( $path, $fields, $global_setting, $expected );
	}

	/**
	 * REST omission and explicit boolean choices for each global policy.
	 *
	 * @return array
	 */
	public static function rest_policies() {
		$cases = array();
		foreach ( array( false, 1, 0 ) as $global_setting ) {
			$cases[] = array( array(), $global_setting, 2 );
			$cases[] = array( array( 'ssl_verify' => true ), $global_setting, 1 );
			$cases[] = array( array( 'ssl_verify' => false ), $global_setting, 0 );
		}
		return $cases;
	}

	/**
	 * REST authentication defines a request constant, so isolate these cases.
	 *
	 * @dataProvider rest_policies
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param array $fields Request fields.
	 * @param mixed $global_setting Global TLS option, or false when missing.
	 * @param int   $expected Stored policy.
	 */
	public function test_rest_registration_transport_and_saved_policy( $fields, $global_setting, $expected ) {
		$this->assert_registration_policy( 'rest', $fields, $global_setting, $expected );
	}

	/**
	 * Native abilities requests and direct adapters keep explicit choices.
	 *
	 * @return array
	 */
	public static function abilities_policies() {
		$cases = array();
		foreach ( array( false, 1, 0 ) as $global_setting ) {
			foreach ( array( 'abilities', 'abilities-adapter' ) as $path ) {
				foreach ( array( 'omitted', 0, 1, 2 ) as $choice ) {
					$fields = 'omitted' === $choice ? array() : array( 'verify_certificate' => $choice );
					$cases[ $path . '-' . $choice . '-global-' . ( false === $global_setting ? 'missing' : (string) $global_setting ) ] = array( $path, $fields, $global_setting, 'omitted' === $choice ? 2 : $choice );
				}
			}
		}
		return $cases;
	}

	/**
	 * Exercise authenticated abilities permissions, registration and storage.
	 *
	 * @dataProvider abilities_policies
	 * @param string $path Abilities route or adapter.
	 * @param array  $fields Request fields.
	 * @param mixed  $global_setting Global TLS option, or false when missing.
	 * @param int    $expected Stored policy.
	 */
	public function test_abilities_registration_transport_and_saved_policy( $path, $fields, $global_setting, $expected ) {
		$this->assert_registration_policy( $path, $fields, $global_setting, $expected );
	}

	/**
	 * Keep XML constants from being replayed before the isolated WP bootstrap.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( ! in_array( $this->getName( false ), array( 'test_rest_registration_transport_and_saved_policy', 'test_rest_boolean_schema_keeps_accepted_values' ), true ) ) {
			return parent::run( $result );
		}
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/**
	 * Assert the real registration result, transport options and first sync.
	 *
	 * @param string $path Registration entry path.
	 * @param array  $fields Request fields.
	 * @param mixed  $global_setting Global TLS option, or false when missing.
	 * @param int    $expected Stored policy.
	 */
	private function assert_registration_policy( $path, $fields, $global_setting, $expected ) {
		$this->global_policy( $global_setting );
		$site      = $this->register( $path, $fields );
		$transport = $this->transport( $this->captured, $global_setting );
		$verify    = 1 === $expected || ( 2 === $expected && 0 !== $global_setting );
		$this->assertSame( $verify ? 2 : false, $transport['host'] );
		$this->assertSame( $verify, $transport['peer'] );
		$this->assertSame( $expected, (int) $site->verify_certificate );
		$this->assertSame( $expected, $this->captured );
		$sync_policy = null;
		$sync        = function ( $pre, $website ) use ( &$sync_policy ) {
			$sync_policy = $website->verify_certificate;
			return array();
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $sync, 20, 2 );
		MainWP_Connect::fetch_url_authed( $site, 'stats', array() );
		remove_filter( 'mainwp_fetch_url_authed_pre', $sync, 20 );
		$this->assertSame( $expected, (int) $sync_policy );
		$this->assertSame( $transport, $this->transport( $sync_policy, $global_setting ) );
	}

	/**
	 * Distinguish a user choice from the checked default, including cached requests.
	 *
	 * @return array
	 */
	public static function explicit_ui_choices() {
		return array(
			'cached default' => array( 1, null, 2 ),
			'untouched default' => array( 1, 0, 2 ),
			'unchecked' => array( 0, 1, 0 ),
			'rechecked' => array( 1, 1, 1 ),
			'string flag' => array( '1', '1', 1 ),
			'boolean flag' => array( true, true, 1 ),
			'invalid string flag' => array( 1, 'true', 2 ),
			'invalid array flag' => array( 1, array( 1 ), 2 ),
		);
	}

	/**
	 * Preflight, registration, storage and first sync honor the same UI choice.
	 *
	 * @dataProvider explicit_ui_choices
	 * @param mixed $choice Checkbox value.
	 * @param mixed $explicit Optional user-choice flag.
	 * @param int   $expected Saved policy.
	 */
	public function test_explicit_ui_choice_matches_preflight_and_registration( $choice, $explicit, $expected ) {
		$this->global_policy( 0 );
		$fields = array( 'verify_certificate' => $choice );
		if ( null !== $explicit ) {
			$fields['ssl_verify_explicit'] = $explicit;
		}
		$_POST = array_merge( array( 'url' => 'https://explicit-ui.example.com/', 'admin' => 'admin' ), $fields );
		$stop = function () {
			throw new \RuntimeException( 'preflight-complete' );
		};
		add_action( 'mainwp_check_site_result', $stop );
		try {
			Handler::check_site();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'preflight-complete', $e->getMessage() );
		} finally {
			remove_action( 'mainwp_check_site_result', $stop );
		}
		$this->assertSame( $expected, $this->captured, 'Preflight must use the selected policy.' );
		$transport = $this->transport( $this->captured, 0 );
		$this->assertSame( 1 === $expected, $transport['peer'] );
		unset( $fields['verify_certificate'] );
		$fields['ssl_verify'] = $choice;
		$this->assert_registration_policy( 'ui', $fields, 0, $expected );
	}

	/**
	 * Preserve explicit import verification while UI defaults inherit.
	 */
	public function test_preflight_distinguishes_import_explicit_one() {
		$this->global_policy( 0 );
		foreach ( array(
			array( 'verify_certificate' => 1 ),
			array( 'verify_certificate' => 0 ),
			array(),
			array(
				'verify_certificate' => 1,
				'check_me'           => 1,
			),
		) as $index => $fields ) {
			$_POST = array_merge(
				array(
					'url'   => 'https://preflight.example.com/',
					'admin' => 'admin',
				),
				$fields
			);
			$stop  = function () {
				throw new \RuntimeException( 'preflight-complete' );
			};
			add_action( 'mainwp_check_site_result', $stop );
			try {
				Handler::check_site();
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'preflight-complete', $e->getMessage() );
			}
			remove_action( 'mainwp_check_site_result', $stop );
			$this->assertSame( array( 2, 0, 2, 1 )[ $index ], $this->captured );
		}
	}

	/**
	 * Leave untouched import controls on the global policy.
	 */
	public function test_import_untouched_control_and_rendered_rows() {
		$fields = Page::mainwp_managesites_form_import_sites_fields();
		$this->assertSame( '', $fields['optional_fields']['verify_certificate']['default'] ?? null );
	}

	/**
	 * Carry rendered import policies into preflight and registration.
	 */
	public function test_import_blank_and_explicit_values_reach_registration() {
		foreach ( array(
			''  => 2,
			'0' => 0,
			'1' => 1,
			'2' => 2,
		) as $value => $expected ) {
			$_POST    = array(
				'_wpnonce'                  => wp_create_nonce( 'mainwp-admin-nonce' ),
				'mainwp_managesites_import' => array(
					array(
						'site_url'           => 'https://import.example.com/',
						'site_name'          => 'Import',
						'admin_name'         => 'admin',
						'verify_certificate' => (string) $value,
					),
				),
			);
			$_REQUEST = $_POST;
			ob_start();
			View::render_import_sites();
			$html = ob_get_clean();
			preg_match( '/encoded-data="([^"]+)"/', $html, $match );
			$row = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
			$this->assertSame( $expected, $row['verify_certificate'] );
			$_POST = array(
				'url'                => 'https://import.example.com/',
				'admin'              => 'admin',
				'verify_certificate' => $row['verify_certificate'],
				'check_me'           => 1,
			);
			$stop  = function () {
				throw new \RuntimeException( 'done' );
			};
			add_action( 'mainwp_check_site_result', $stop );
			try {
				Handler::check_site();
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'done', $e->getMessage() );
			}
			remove_action( 'mainwp_check_site_result', $stop );
			$this->assertSame( $expected, $this->captured );
			$site = $this->register(
				'import',
				array(
					'verify_certificate' => $row['verify_certificate'],
					'bulk'               => true,
				)
			);
			$this->assertSame( $expected, (int) $site->verify_certificate );
			$this->assertSame( $expected, $this->captured );
		}
	}

	/**
	 * Import choices saved before the user reloads the form.
	 *
	 * @return array
	 */
	public static function autosave_policies() {
		$cases = array();
		foreach ( array( 0, 1 ) as $global_setting ) {
			foreach ( array( null, '', '0', '1', '2' ) as $value ) {
				$expected = in_array( $value, array( '0', '1' ), true ) ? (int) $value : 2;
				$cases[]  = array( $value, $global_setting, $expected );
			}
		}
		return $cases;
	}

	/**
	 * Autosaving and reloading a default row must retain global inheritance.
	 *
	 * @dataProvider autosave_policies
	 * @param mixed $value Submitted certificate choice, or null when omitted.
	 * @param int   $global_setting Global verification setting.
	 * @param int   $expected Expected saved policy.
	 */
	public function test_temporary_import_rows_keep_policy_after_reload( $value, $global_setting, $expected ) {
		$this->global_policy( $global_setting );
		$_POST = array(
			'security'       => wp_create_nonce( 'mainwp_save_temp_import_website' ),
			'status'         => 'save_temp',
			'row_index'      => 0,
			'site_url'       => 'https://autosave.example.com/',
			'admin_name'     => 'fixture-admin',
			'admin_password' => 'fixture-only',
		);
		if ( null !== $value ) {
			$_POST['verify_certificate'] = $value;
		}
		$_REQUEST = $_POST;
		$stop     = static function () {
			return static function () {
				throw new \RuntimeException( 'autosave-complete' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $stop );
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Post_Handler::instance()->ajax_save_temp_import_website();
			$this->fail( 'Autosave must complete its JSON response.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'autosave-complete', $error->getMessage() );
		} finally {
			$output = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $stop );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$this->assertTrue( json_decode( $output, true )['success'], $output );
		$rows = MainWP_DB::instance()->get_general_option( 'temp_import_sites', 'array' );
		$this->assertSame( $expected, $rows[0]['verify_certificate'] );
		ob_start();
		Page::mainwp_managesites_form_import_sites();
		$html = ob_get_clean();
		$this->assertSame( 1, preg_match( '/<input[^>]*name="mainwp_managesites_import\[0\]\[verify_certificate\]"[^>]*value="([^"]*)"/', $html, $match ) );
		$this->assertSame( (string) $expected, $match[1] );
		$site = $this->register(
			'import',
			array(
				'verify_certificate' => $match[1],
				'bulk'               => true,
			)
		);
		$this->assertSame( $expected, (int) $site->verify_certificate );
		$this->assertSame( $expected, $this->captured );
		$this->assertSame( 1 === $expected || ( 2 === $expected && 1 === $global_setting ), $this->transport( $this->captured, $global_setting )['peer'] );
	}

	/**
	 * Preserve blank, numeric and legacy CSV certificate choices.
	 */
	public function test_csv_rows_prepare_then_preflight_and_register() {
		$csv = "name,url,admin,pwd,groups,id,httpuser,httppass,verify,ssl\n";
		foreach ( array( '', '1', '0', '2', 'T', 'Y' ) as $value ) {
			$csv .= 'CSV,https://csv.example.com/,admin,,,,,,' . $value . ",auto\n";
		}
		$rows = $this->fixture( 'sec39-import.inc', array( 'csv' => $csv ) );
		$this->assertCount( 6, $rows );
		$this->global_policy( 0 );
		foreach ( $rows as $index => $row ) {
			$expected = array( 2, 1, 0, 2, 1, 0 )[ $index ];
			$_POST    = array(
				'url'                => $row['url'],
				'admin'              => $row['adminname'],
				'verify_certificate' => $row['verify_certificate'],
				'check_me'           => 1,
			);
			$stop     = function () {
				throw new \RuntimeException( 'csv-preflight' );
			};
			add_action( 'mainwp_check_site_result', $stop );
			try {
				Handler::check_site();
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'csv-preflight', $e->getMessage() );
			}
			remove_action( 'mainwp_check_site_result', $stop );
			$this->assertSame( $expected, $this->captured );
			$this->assertSame( 1 === $expected, $this->transport( $this->captured, 0 )['peer'] );
			$site = $this->register(
				'import',
				array(
					'verify_certificate' => $row['verify_certificate'],
					'bulk'               => true,
				)
			);
			$this->assertSame( $expected, (int) $site->verify_certificate );
			$this->assertSame( $expected, $this->captured );
		}
	}

	/**
	 * Keep every boolean representation accepted by the existing REST schema.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_boolean_schema_keeps_accepted_values() {
		foreach ( array( true, false, 1, 0, '1', '0', 'true', 'false' ) as $value ) {
			$site     = $this->register( 'rest', array( 'ssl_verify' => $value ) );
			$expected = in_array( $value, array( true, 1, '1', 'true' ), true ) ? 1 : 0;
			$this->assertSame( $expected, (int) $site->verify_certificate );
			$this->assertSame( $expected, $this->captured );
		}
	}

	/**
	 * Keep missing and unsupported direct inputs on the global policy.
	 */
	public function test_direct_invalid_and_null_inputs_inherit_global() {
		foreach ( array( null, '', 'garbage', array( 0 ), new \stdClass() ) as $value ) {
			$site = $this->register( 'direct', array( 'ssl_verify' => $value ) );
			$this->assertSame( 2, $this->captured );
			$this->assertSame( 2, (int) $site->verify_certificate );
		}
	}

	/**
	 * Verify Vultr with Child verification off and preserve the filter opt-out.
	 */
	public function test_vultr_verifies_independently_and_allows_filter_opt_out() {
		$this->assertSame(
			array(
				'host' => 2,
				'peer' => true,
			),
			$this->transport( null, 0, array( 'provider' => true ) )
		);
		$this->assertSame(
			array(
				'host' => false,
				'peer' => false,
			),
			$this->transport(
				null,
				0,
				array(
					'provider' => true,
					'filter'   => false,
				)
			)
		);
	}

	/**
	 * Exercise registration and sync options against a self-signed endpoint.
	 */
	public function test_runtime_self_signed_compatibility() {
		$key  = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		$csr  = openssl_csr_new( array( 'commonName' => 'localhost' ), $key );
		$cert = openssl_csr_sign( $csr, null, $key, 1 );
		$file = tempnam( sys_get_temp_dir(), 'sec39-cert-' );
		openssl_x509_export( $cert, $pem );
		openssl_pkey_export( $key, $private );
		file_put_contents( $file, $pem . $private );
		chmod( $file, 0600 );
		$server = null;
		try {
			$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
			$this->assertIsResource( $socket, 'Cannot reserve a loopback port for the TLS fixture.' );
			$address = stream_socket_get_name( $socket, false );
			fclose( $socket );
			$server = proc_open( array( 'openssl', 's_server', '-accept', $address, '-cert', $file, '-www', '-quiet' ), array( array( 'file', '/dev/null', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );
			$this->assertIsResource( $server, 'Cannot start the OpenSSL TLS fixture.' );
			$ready    = false;
			$deadline = microtime( true ) + 5;
			do {
				$probe = @stream_socket_client( 'tcp://' . $address, $probe_errno, $probe_error, 0.1 );
				if ( is_resource( $probe ) ) {
					fclose( $probe );
					$ready = true;
					break;
				}
				$status = proc_get_status( $server );
				if ( ! $status['running'] ) {
					break;
				}
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			$this->assertTrue( $ready, 'The OpenSSL TLS fixture did not accept connections within five seconds.' );
			foreach ( array( false, 1, 0 ) as $global_setting ) {
				$this->global_policy( $global_setting );
				foreach ( array( 1, 0 ) as $checked ) {
					$_POST = array(
						'url'                => 'https://preflight.example.com/',
						'admin'              => 'admin',
						'verify_certificate' => $checked,
					);
					$stop  = function () {
						throw new \RuntimeException( 'done' );
					};
					add_action( 'mainwp_check_site_result', $stop );
					try {
						Handler::check_site();
					} catch ( \RuntimeException $e ) {
						$this->assertSame( 'done', $e->getMessage() );
					}
					remove_action( 'mainwp_check_site_result', $stop );
					$preflight    = $this->transport( $this->captured, $global_setting, array( 'local_url' => 'https://' . $address . '/' ) );
					$site         = $this->register( 'ui', array( 'ssl_verify' => $checked ) );
					$registration = $this->transport( $this->captured, $global_setting, array( 'local_url' => 'https://' . $address . '/' ) );
					$sync_row     = null;
					$sync         = function ( $pre, $website ) use ( &$sync_row ) {
						$sync_row = $website;
						return array();
					};
					add_filter( 'mainwp_fetch_url_authed_pre', $sync, 20, 2 );
					MainWP_Connect::fetch_url_authed( $site, 'stats', array() );
					remove_filter( 'mainwp_fetch_url_authed_pre', $sync, 20 );
					$first_sync = $this->transport( $sync_row->verify_certificate, $global_setting, array( 'local_url' => 'https://' . $address . '/' ) );
					if ( getenv( 'SEC39_BASELINE' ) ) {
						$this->assertSame( 2, (int) $site->verify_certificate );
						$this->assertFalse( $registration['peer'] );
						$this->assertTrue( $registration['self_signed_ok'] );
						$this->assertSame( 0 === $global_setting, $first_sync['self_signed_ok'] );
					} else {
						$this->assertSame( $checked ? 2 : 0, (int) $site->verify_certificate );
						$this->assertSame( $preflight, $registration );
						$this->assertSame( $registration, $first_sync );
						$this->assertSame( ! $checked || 0 === $global_setting, $first_sync['self_signed_ok'] );
					}
				}
			}
		} finally {
			if ( is_resource( $server ) ) {
				proc_terminate( $server );
				proc_close( $server );
			}
			unlink( $file );
		}
	}
}
