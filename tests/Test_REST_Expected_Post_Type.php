<?php
/**
 * REST v2 post and page type checks at the Child request boundary.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/** Content routes must reject another type before dispatching a mutation. */
class Test_REST_Expected_Post_Type extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Connected site ID.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Recorded Child requests.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Child get_edit response.
	 *
	 * @var array|string
	 */
	private $lookup;

	/**
	 * Optional late mutation reply.
	 *
	 * @var array|null
	 */
	private $mutation_reply;

	/**
	 * Read-only listing response, or an exception to simulate unavailable listing.
	 *
	 * @var mixed
	 */
	private $listing = array();

	/**
	 * Simulated edit lock writes on Child.
	 *
	 * @var int
	 */
	private $edit_locks = 0;

	/**
	 * Whether the Child honors the optional type field.
	 *
	 * @var bool
	 */
	private $new_child = false;

	/**
	 * Previous REST authentication singleton.
	 *
	 * @var mixed
	 */
	private $previous_auth;

	/**
	 * Previous REST server.
	 *
	 * @var mixed
	 */
	private $previous_server;

	/** Set up a connected site and the real content routes. */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$this->previous_server = $wp_rest_server;
		$this->previous_auth   = \MainWP_REST_Authentication::$instance;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->site_id = $this->create_test_site();
		$this->assertGreaterThan( 0, $this->site_id );

		// API key authentication defines a process-wide bypass constant. Keep the
		// content tests in the shared process without introducing that side effect.
		$auth = $this->getMockBuilder( \MainWP_REST_Authentication::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_valid_permissions' ) )
			->getMock();
		$auth->method( 'is_valid_permissions' )->willReturn( true );
		\MainWP_REST_Authentication::$instance = $auth;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		( new \MainWP_Rest_Posts_Controller() )->register_routes();
		( new \MainWP_Rest_Pages_Controller() )->register_routes();

		$this->calls = array();
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_request' ), 10, 4 );
	}

	/** Restore shared process state even when a refusal assertion fails. */
	public function tear_down() {
		global $wp_rest_server;
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_request' ), 10 );
		\MainWP_REST_Authentication::$instance = $this->previous_auth;
		$wp_rest_server                        = $this->previous_server;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Replace network transport while retaining production lookups and handlers.
	 *
	 * @param mixed  $pre     Earlier response.
	 * @param object $website Connected site.
	 * @param string $what    Child command.
	 * @param array  $params  Child arguments.
	 * @return array Child response.
	 * @throws \MainWP\Dashboard\MainWP_Exception When the listing is unavailable.
	 */
	public function child_request( $pre, $website, $what, $params ) {
		$this->assertSame( $this->site_id, (int) $website->id );
		$this->calls[] = array(
			'command' => $what,
			'params'  => $params,
		);
		if ( 'get_all_posts' === $what ) {
			if ( $this->listing instanceof \MainWP\Dashboard\MainWP_Exception ) {
				throw $this->listing;
			}
			return $this->listing;
		}
		if ( $this->new_child && isset( $params['expected_post_type'], $this->lookup['my_post']['new_post'] ) ) {
			$post = json_decode( base64_decode( $this->lookup['my_post']['new_post'] ), true );
			if ( $params['expected_post_type'] !== $post['post_type'] ) {
				return array(
					'status' => 'FAIL',
					'error'  => 'Unexpected post type.',
				);
			}
		}
		if ( 'post_action' === $what && 'get_edit' === ( $params['action'] ?? '' ) ) {
			++$this->edit_locks;
			return $this->lookup;
		}
		$this->assertContains( $what, array( 'post_action', 'newpost' ), 'Unexpected Child command.' );
		if ( null !== $this->mutation_reply ) {
			return $this->mutation_reply;
		}
		return array(
			'status'   => 'SUCCESS',
			'my_post'  => array( 'id' => 317 ),
			'added'    => true,
			'added_id' => 317,
		);
	}

	/**
	 * Both HTTP update methods and every accepted status action are covered.
	 *
	 * @return array Route cases.
	 */
	public static function content_routes(): array {
		$cases = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			$cases[ $type . '-get' ]    = array( $type, 'GET', '', '' );
			$cases[ $type . '-delete' ] = array( $type, 'DELETE', '/delete', '' );
			foreach ( array( 'PUT', 'PATCH' ) as $method ) {
				$cases[ $type . '-edit-' . $method ] = array( $type, $method, '/edit', '' );
				foreach ( array( 'unpublish', 'publish', 'approve', 'trash', 'delete', 'restore' ) as $status ) {
					$cases[ $type . '-' . $method . '-' . $status ] = array( $type, $method, '/update-status', $status );
				}
			}
		}
		return $cases;
	}

	/**
	 * Old Childs return the item's actual type without refusing the request.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_old_child_mismatched_type_is_refused_before_mutation( $type, $method, $suffix, $status ): void {
		$actual = 'post' === $type ? 'page' : 'post';
		// Stock Child page edit replies label every ID as a page.
		$this->lookup  = $this->edit_reply( 'page' === $type ? 'page' : $actual );
		$this->listing = array(
			array(
				'id'        => 317,
				'post_type' => $actual,
			),
		);
		$response      = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_preflight();
		$this->assertSame( array(), $this->edit_calls(), 'A known mismatch must not write an edit lock.' );
		$this->assertSame( 0, $this->edit_locks );
		$this->assertSame( array(), $this->mutations(), 'Mutating Child calls went through: ' . wp_json_encode( $this->mutations() ) );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * New Child refusals retain their useful message on every content route.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_new_child_fail_is_a_clear_type_error( $type, $method, $suffix, $status ): void {
		$actual       = 'post' === $type ? 'page' : 'post';
		$this->lookup = array(
			'status' => 'FAIL',
			'error'  => "This item is a {$actual}, not a {$type}.",
		);
		$response     = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_lookup( $type );
		$this->assertSame( array(), $this->mutations() );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * Older Child plugins ignore the optional field and keep the public result.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_right_type_old_child_preserves_response( $type, $method, $suffix, $status ): void {
		$this->lookup  = $this->edit_reply( $type );
		$this->listing = array(
			array(
				'id'        => '317',
				'post_type' => $type,
			),
		);
		$this->assert_success( $this->dispatch_content( $type, $method, $suffix, $status ), $type, $suffix );
		$this->assert_dispatch( $type, $suffix, $status );
	}

	/**
	 * A new Child's successful reply has the same shape as its legacy reply.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_right_type_new_child_preserves_response( $type, $method, $suffix, $status ): void {
		$this->new_child = true;
		$this->lookup    = $this->edit_reply( $type );
		$this->listing   = array(
			array(
				'id'        => 317,
				'post_type' => $type,
			),
		);
		$this->assert_success( $this->dispatch_content( $type, $method, $suffix, $status ), $type, $suffix );
		$this->assert_dispatch( $type, $suffix, $status );
	}

	/**
	 * Missing post_type in an old Child reply must keep today's behavior.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_missing_post_type_preserves_response( $type, $method, $suffix, $status ): void {
		$this->lookup = $this->edit_reply( null );
		$this->assert_success( $this->dispatch_content( $type, $method, $suffix, $status ), $type, $suffix, true );
		$this->assert_dispatch( $type, $suffix, $status );
	}

	/**
	 * The optional field belongs on both the lookup and the mutating request.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_expected_post_type_is_sent_on_lookup_and_mutation( $type, $method, $suffix, $status ): void {
		$this->lookup = $this->edit_reply( $type );
		$this->assert_success( $this->dispatch_content( $type, $method, $suffix, $status ), $type, $suffix );
		$this->assert_dispatch( $type, $suffix, $status );
		foreach ( $this->calls as $call ) {
			if ( 'get_all_posts' === $call['command'] ) {
				continue;
			}
			$this->assertSame( $type, $call['params']['expected_post_type'] ?? null, $call['command'] . ' must carry expected_post_type.' );
		}
	}

	/**
	 * The edit reply remains checked when the read-only listing is inconclusive.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_decoded_type_mismatch_is_refused_after_inconclusive_listing( $type, $method, $suffix, $status ): void {
		$actual       = 'post' === $type ? 'page' : 'post';
		$this->lookup = $this->edit_reply( $actual );
		$response     = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_lookup( $type );
		$this->assertSame( array(), $this->mutations() );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * Every standard status must be included in the read-only lookup.
	 *
	 * @return array Status cases.
	 */
	public static function preflight_statuses(): array {
		$cases = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ) as $status ) {
				$cases[ $type . '-' . $status ] = array( $type, $status );
			}
		}
		return $cases;
	}

	/**
	 * Preflight-detected mismatches never reach the lock-writing edit lookup.
	 *
	 * @dataProvider preflight_statuses
	 * @param string $type   Controller type.
	 * @param string $status Actual item status.
	 */
	public function test_wrong_type_in_each_standard_status_is_refused_without_edit_lock( $type, $status ): void {
		$actual        = 'post' === $type ? 'page' : 'post';
		$this->lookup  = $this->edit_reply( 'page' === $type ? 'page' : $actual );
		$this->listing = array(
			array(
				'id'        => 317,
				'post_type' => $actual,
				'status'    => $status,
			),
		);
		$response      = $this->dispatch_content( $type, 'DELETE', '/delete', '' );
		$this->assert_preflight();
		$this->assertSame( array(), $this->edit_calls() );
		$this->assertSame( array(), $this->mutations() );
		$this->assertSame( 0, $this->edit_locks );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * Unsupported listings and unrelated IDs cannot establish an actual type.
	 *
	 * @return array Fallback cases.
	 */
	public static function inconclusive_listings(): array {
		$cases = array();
		foreach ( self::content_routes() as $name => $route ) {
			if ( 'PATCH' === $route[1] || ( '/update-status' === $route[2] && 'publish' !== $route[3] ) ) {
				continue;
			}
			foreach ( array( 'empty', 'scalar', 'malformed-row', 'other-id', 'invalid-id', 'missing-id', 'missing-type', 'empty-type', 'array-type', 'failure', 'exception' ) as $shape ) {
				$cases[ $name . '-' . $shape ] = array_merge( $route, array( $shape ) );
			}
		}
		return $cases;
	}

	/**
	 * Inconclusive preflight retains the original missing-type response path.
	 *
	 * @dataProvider inconclusive_listings
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 * @param string $shape  Listing shape.
	 */
	public function test_inconclusive_listing_preserves_legacy_response( $type, $method, $suffix, $status, $shape ): void {
		$actual        = 'post' === $type ? 'page' : 'post';
		$listings      = array(
			'empty'         => array(),
			'scalar'        => 'unsupported',
			'malformed-row' => array( 'invalid row' ),
			'other-id'      => array(
				array(
					'id'        => 318,
					'post_type' => $actual,
				),
			),
			'invalid-id'    => array(
				array(
					'id'        => '317invalid',
					'post_type' => $actual,
				),
			),
			'missing-id'    => array( array( 'post_type' => $actual ) ),
			'missing-type'  => array( array( 'id' => 317 ) ),
			'empty-type'    => array(
				array(
					'id'        => 317,
					'post_type' => '',
				),
			),
			'array-type'    => array(
				array(
					'id'        => 317,
					'post_type' => array( $actual ),
				),
			),
			'failure'       => array(
				'status' => 'FAIL',
				'error'  => 'Unsupported listing.',
			),
			'exception'     => new \MainWP\Dashboard\MainWP_Exception( 'Listing unavailable.' ),
		);
		$this->listing = $listings[ $shape ];
		$this->lookup  = $this->edit_reply( null );
		$response      = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_success( $response, $type, $suffix, true );
		$this->assert_dispatch( $type, $suffix, $status );
		$this->assert_preflight();
	}

	/**
	 * Both plain refusal fields must survive a late Child type check.
	 *
	 * @return array Mutation cases.
	 */
	public static function late_refusal_routes(): array {
		$cases = array();
		foreach ( self::content_routes() as $name => $route ) {
			if ( '' === $route[2] ) {
				continue;
			}
			foreach ( array( 'error', 'message' ) as $field ) {
				$cases[ $name . '-' . $field ] = array_merge( $route, array( $field ) );
			}
		}
		return $cases;
	}

	/**
	 * A matching lookup can be followed by a Child refusal during mutation.
	 *
	 * @dataProvider late_refusal_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 * @param string $field  Plain refusal field.
	 */
	public function test_late_mutation_fail_is_a_clear_type_error( $type, $method, $suffix, $status, $field ): void {
		$actual               = 'post' === $type ? 'page' : 'post';
		$this->lookup         = $this->edit_reply( $type );
		$this->mutation_reply = array(
			'status' => 'FAIL',
			$field   => "<b>This item is a {$actual}, not a {$type}.</b>",
		);
		$response             = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_dispatch( $type, $suffix, $status );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * Child may put the lookup refusal in message rather than error.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_lookup_fail_message_is_a_clear_type_error( $type, $method, $suffix, $status ): void {
		$actual       = 'post' === $type ? 'page' : 'post';
		$this->lookup = array(
			'status'  => 'FAIL',
			'error'   => array( 'ignored' ),
			'message' => "<b>This item is a {$actual}, not a {$type}.</b>",
		);
		$response     = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_lookup( $type );
		$this->assertSame( array(), $this->mutations() );
		$this->assert_type_error( $response, $actual, $type );
	}

	/**
	 * Other actual types get a fixed expected-type message.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_other_actual_type_is_refused( $type, $method, $suffix, $status ): void {
		$this->lookup = $this->edit_reply( 'attachment' );
		$response     = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_lookup( $type );
		$this->assertSame( array(), $this->mutations() );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'post_not_exist', $response->get_data()['code'] );
		$this->assertSame( "This item is not a {$type}.", $response->get_data()['message'] );
	}

	/**
	 * Status updates must not start decoding unrelated edit metadata.
	 *
	 * @return array Invalid replies.
	 */
	public static function malformed_replies(): array {
		$cases = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( 'reply', 'my_post', 'new_post', 'post_custom', 'post_featured_image', 'featured_image_data', 'mainwp_upload_dir', 'child_upload_dir', 'post_gallery_images', 'post_category' ) as $field ) {
				$cases[ $type . '-' . $field ] = array( $type, $field );
			}
		}
		return $cases;
	}

	/**
	 * Unusable edit types and unrelated metadata keep legacy status behavior.
	 *
	 * @dataProvider malformed_replies
	 * @param string $type  Controller type.
	 * @param string $field Invalid field.
	 */
	public function test_unusable_edit_metadata_preserves_status_behavior( $type, $field ): void {
		$this->lookup = $this->edit_reply( $type );
		if ( 'reply' === $field ) {
			$this->lookup = 'invalid reply';
		} elseif ( 'my_post' === $field ) {
			$this->lookup['my_post'] = 'invalid container';
		} else {
			$this->lookup['my_post'][ $field ] = array( 'invalid encoded data' );
		}
		$response = $this->dispatch_content( $type, 'PUT', '/update-status', 'publish' );
		$this->assert_lookup( $type );
		if ( 'reply' === $field ) {
			$this->assertSame( array(), $this->mutations() );
			$this->assertSame( 'post_not_exist', $response->get_data()['code'] );
			$this->assertSame( ucfirst( $type ) . ' not exist.', $response->get_data()['message'] );
		} else {
			$this->assert_success( $response, $type, '/update-status' );
			$this->assert_dispatch( $type, '/update-status', 'publish' );
		}
	}

	/**
	 * Generic Child failures retain each route's existing response.
	 *
	 * @return array Generic failure cases.
	 */
	public static function generic_failure_routes(): array {
		$cases = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( '', '/edit', '/delete', '/update-status' ) as $suffix ) {
				foreach ( array( false, true ) as $invalid_fields ) {
					$cases[] = array( $type, $suffix, $invalid_fields );
				}
			}
		}
		return $cases;
	}

	/**
	 * Missing or non-string refusal messages must keep generic failures.
	 *
	 * @dataProvider generic_failure_routes
	 * @param string $type           Controller type.
	 * @param string $suffix         Route suffix.
	 * @param bool   $invalid_fields Whether to supply non-string messages.
	 */
	public function test_fail_without_plain_message_preserves_response( $type, $suffix, $invalid_fields ): void {
		$reply = array( 'status' => 'FAIL' );
		if ( $invalid_fields ) {
			$reply['error']   = array( 'invalid message' );
			$reply['message'] = 42;
		}
		$this->lookup = '' === $suffix ? $reply : $this->edit_reply( $type );
		if ( '' !== $suffix ) {
			$this->mutation_reply = $reply;
		}
		$method   = '' === $suffix ? 'GET' : ( '/delete' === $suffix ? 'DELETE' : 'PUT' );
		$response = $this->dispatch_content( $type, $method, $suffix, 'publish' );
		$this->assert_dispatch( $type, $suffix, 'publish' );
		$data = $response->get_data();
		if ( '' === $suffix ) {
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'post_not_exist', $data['code'] );
			$this->assertSame( ucfirst( $type ) . ' not exist.', $data['message'] );
			return;
		}
		$action  = '/edit' === $suffix ? 'Edit' : ( '/delete' === $suffix ? 'Delete' : 'Update' );
		$message = $action . ' ' . $type . ( '/update-status' === $suffix ? ' status' : '' ) . ' failed.';
		if ( 'post' === $type && '/edit' !== $suffix ) {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array(
					'success' => 0,
					'message' => $message,
				),
				$data
			);
		} else {
			$code = '/update-status' === $suffix ? 'update_page_status_failed' : strtolower( $action ) . '_' . $type . '_failed';
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( $code, $data['code'] );
			$this->assertSame( $message, $data['message'] );
		}
	}

	/**
	 * Unrelated plain Child failures keep the existing route response.
	 *
	 * @dataProvider generic_failure_routes
	 * @param string $type           Controller type.
	 * @param string $suffix         Route suffix.
	 * @param bool   $invalid_fields Whether to use the message field.
	 */
	public function test_unrelated_plain_fail_preserves_response( $type, $suffix, $invalid_fields ): void {
		$field        = $invalid_fields ? 'message' : 'error';
		$reply        = array(
			'status' => 'FAIL',
			$field   => 'The item could not be saved.',
		);
		$this->lookup = '' === $suffix ? $reply : $this->edit_reply( $type );
		if ( '' !== $suffix ) {
			$this->mutation_reply = $reply;
		}
		$method   = '' === $suffix ? 'GET' : ( '/delete' === $suffix ? 'DELETE' : 'PUT' );
		$response = $this->dispatch_content( $type, $method, $suffix, 'publish' );
		$this->assert_dispatch( $type, $suffix, 'publish' );
		$data = $response->get_data();
		if ( '' === $suffix ) {
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'post_not_exist', $data['code'] );
			$this->assertSame( ucfirst( $type ) . ' not exist.', $data['message'] );
			return;
		}
		$action  = '/edit' === $suffix ? 'Edit' : ( '/delete' === $suffix ? 'Delete' : 'Update' );
		$message = $action . ' ' . $type . ( '/update-status' === $suffix ? ' status' : '' ) . ' failed.';
		if ( 'post' === $type && '/edit' !== $suffix ) {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array(
					'success' => 0,
					'message' => $message,
				),
				$data
			);
		} else {
			$code = '/update-status' === $suffix ? 'update_page_status_failed' : strtolower( $action ) . '_' . $type . '_failed';
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( $code, $data['code'] );
			$this->assertSame( $message, $data['message'] );
		}
	}


	/**
	 * Stock page-shaped replies remain the fallback when the listing fails.
	 *
	 * @dataProvider content_routes
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 */
	public function test_inconclusive_listing_preserves_stock_page_reply( $type, $method, $suffix, $status ): void {
		$this->lookup  = $this->edit_reply( $type );
		$this->listing = new \MainWP\Dashboard\MainWP_Exception( 'Listing unavailable.' );
		$response      = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_success( $response, $type, $suffix );
		$this->assertSame( 1, $this->edit_locks, 'Legacy fallback still calls the original edit reader.' );
	}


	/**
	 * Each content route receives the exact MWP-2020 English refusal forms.
	 *
	 * @return array Refusal cases.
	 */
	public static function child_refusal_messages(): array {
		$cases = array();
		foreach ( self::content_routes() as $name => $route ) {
			if ( 'PATCH' === $route[1] || ( '/update-status' === $route[2] && 'publish' !== $route[3] ) ) {
				continue;
			}
			foreach ( array( 'attachment', 'custom_type', 'missing', 'invalid' ) as $reason ) {
				$cases[ $name . '-' . $reason ] = array_merge( $route, array( $reason ) );
			}
		}
		return $cases;
	}

	/**
	 * MWP-2020 refusals remain clear for custom types and missing targets.
	 *
	 * @dataProvider child_refusal_messages
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 * @param string $reason Child refusal reason.
	 */
	public function test_exact_child_refusal_forms_are_clear( $type, $method, $suffix, $status, $reason ): void {
		$message = "This item is a {$reason}, not a {$type}.";
		if ( 'missing' === $reason ) {
			$message = 'This item no longer exists.';
		} elseif ( 'invalid' === $reason ) {
			$message = 'Expected post type must be post or page.';
		}
		$reply        = array(
			'status' => 'FAIL',
			'error'  => $message,
		);
		$this->lookup = '' === $suffix ? $reply : $this->edit_reply( $type );
		if ( '' !== $suffix ) {
			$this->mutation_reply = $reply;
		}
		$response = $this->dispatch_content( $type, $method, $suffix, $status );
		$this->assert_dispatch( $type, $suffix, $status );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'post_not_exist', $response->get_data()['code'] );
		$this->assertSame( $message, $response->get_data()['message'] );
	}

	/**
	 * Localized Child refusals retain existing failure handling.
	 *
	 * @dataProvider generic_failure_routes
	 * @param string $type           Controller type.
	 * @param string $suffix         Route suffix.
	 * @param bool   $invalid_fields Whether to use the message field.
	 */
	public function test_localized_refusal_preserves_response( $type, $suffix, $invalid_fields ): void {
		$field        = $invalid_fields ? 'message' : 'error';
		$reply        = array(
			'status' => 'FAIL',
			$field   => 'Dieser Eintrag ist eine Seite, kein Beitrag.',
		);
		$this->lookup = '' === $suffix ? $reply : $this->edit_reply( $type );
		if ( '' !== $suffix ) {
			$this->mutation_reply = $reply;
		}
		$method   = '' === $suffix ? 'GET' : ( '/delete' === $suffix ? 'DELETE' : 'PUT' );
		$response = $this->dispatch_content( $type, $method, $suffix, 'publish' );
		$this->assert_dispatch( $type, $suffix, 'publish' );
		$data = $response->get_data();
		if ( '' === $suffix ) {
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'post_not_exist', $data['code'] );
			$this->assertSame( ucfirst( $type ) . ' not exist.', $data['message'] );
			return;
		}
		$action  = '/edit' === $suffix ? 'Edit' : ( '/delete' === $suffix ? 'Delete' : 'Update' );
		$message = $action . ' ' . $type . ( '/update-status' === $suffix ? ' status' : '' ) . ' failed.';
		if ( 'post' === $type && '/edit' !== $suffix ) {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame(
				array(
					'success' => 0,
					'message' => $message,
				),
				$data
			);
		} else {
			$code = '/update-status' === $suffix ? 'update_page_status_failed' : strtolower( $action ) . '_' . $type . '_failed';
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( $code, $data['code'] );
			$this->assertSame( $message, $data['message'] );
		}
	}


	/**
	 * Build the base64 JSON edit payload returned by Child.
	 *
	 * @param string|null $type Actual type, or absent for legacy compatibility.
	 * @return array Child response.
	 */
	private function edit_reply( $type ): array {
		$post = array(
			'edit_id'      => 317,
			'post_title'   => 'Original title',
			'post_content' => 'Original content',
			'post_status'  => 'publish',
		);
		if ( null !== $type ) {
			$post['post_type'] = $type;
		}
		return array(
			'status'  => 'SUCCESS',
			'my_post' => array(
				'new_post'            => base64_encode( wp_json_encode( $post ) ),
				'post_custom'         => base64_encode( wp_json_encode( array( 'fixture' => 'kept' ) ) ),
				'post_featured_image' => base64_encode( 'https://child.example/image.jpg' ),
				'post_gallery_images' => base64_encode( wp_json_encode( array( 23 ) ) ),
				'post_category'       => base64_encode( 'News' ),
				'child_upload_dir'    => base64_encode( wp_json_encode( array( 'baseurl' => 'https://child.example/uploads' ) ) ),
			),
		);
	}

	/**
	 * Dispatch through registered routes, argument validation and real handlers.
	 *
	 * @param string $type   Controller type.
	 * @param string $method HTTP method.
	 * @param string $suffix Route suffix.
	 * @param string $status Status action.
	 * @return \WP_REST_Response REST response.
	 */
	private function dispatch_content( $type, $method, $suffix, $status ) {
		$request = new \WP_REST_Request( $method, '/mainwp/v2/' . $type . 's/' . $this->site_id . '/317' . $suffix );
		if ( '/update-status' === $suffix ) {
			$request->set_body_params( array( 'status' => $status ) );
		} elseif ( '/edit' === $suffix ) {
			$request->set_body_params(
				array(
					'post_title'   => 'Edited title',
					'post_content' => 'Edited content',
					'post_status'  => 'draft',
				)
			);
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Check the lookup reached Child with this existing item's ID.
	 *
	 * @param string $type Controller type.
	 */
	private function assert_lookup( $type ): void {
		$edits = $this->edit_calls();
		$this->assertCount( 1, $edits, 'The route must reach the original Child edit lookup.' );
		$this->assertSame( 'post_action', $edits[0]['command'] );
		$this->assertSame( 'get_edit', $edits[0]['params']['action'] );
		$this->assertSame( 317, (int) $edits[0]['params']['id'] );
		$this->assertSame( $type, $edits[0]['params']['post_type'] );
	}

	/**
	 * Check the existing exact-ID read command and status coverage.
	 */
	private function assert_preflight(): void {
		$listings = $this->listing_calls();
		$this->assertCount( 1, $listings, 'The type preflight must use the existing read-only listing.' );
		$this->assertSame( 'get_all_posts', $this->calls[0]['command'] );
		$params = $listings[0]['params'];
		$this->assertSame( 317, (int) $params['postId'] );
		unset( $params['postId'] );
		$this->assertSame(
			array(
				'post_type'  => 'any',
				'status'     => 'any,trash,auto-draft,inherit',
				'maxRecords' => 1,
			),
			$params
		);
	}

	/**
	 * Return read-only listing calls.
	 *
	 * @return array
	 */
	private function listing_calls(): array {
		return array_values( array_filter( $this->calls, static fn( $call ) => 'get_all_posts' === $call['command'] ) );
	}

	/**
	 * Return calls that write Child edit locks.
	 *
	 * @return array
	 */
	private function edit_calls(): array {
		return array_values( array_filter( $this->calls, static fn( $call ) => 'post_action' === $call['command'] && 'get_edit' === ( $call['params']['action'] ?? '' ) ) );
	}

	/**
	 * Return mutating requests only.
	 *
	 * @return array
	 */
	private function mutations(): array {
		return array_values(
			array_filter(
				$this->calls,
				static function ( $call ) {
					return 'newpost' === $call['command'] || ( 'post_action' === $call['command'] && 'get_edit' !== ( $call['params']['action'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * Refusals must expose the type mismatch rather than a generic lookup error.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param string            $actual   Actual type.
	 * @param string            $type     Controller type.
	 */
	private function assert_type_error( $response, $actual, $type ): void {
		$data = $response->get_data();
		$this->assertSame( "This item is a {$actual}, not a {$type}.", $data['message'], wp_json_encode( $data ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'post_not_exist', $data['code'] );
	}

	/**
	 * Verify the actual mutation reached transport with its original payload.
	 *
	 * @param string $type   Controller type.
	 * @param string $suffix Route suffix.
	 * @param string $status Requested action.
	 */
	private function assert_dispatch( $type, $suffix, $status ): void {
		$this->assert_lookup( $type );
		$mutations = $this->mutations();
		if ( '' === $suffix ) {
			$this->assertSame( array(), $mutations );
			$this->assertCount( 1 + count( $this->listing_calls() ), $this->calls );
			return;
		}
		$this->assertCount( 2 + count( $this->listing_calls() ), $this->calls );
		$this->assertCount( 1, $mutations );
		$call = $mutations[0];
		if ( '/edit' === $suffix ) {
			$this->assertSame( 'newpost', $call['command'] );
			$post = json_decode( base64_decode( $call['params']['new_post'] ), true );
			$this->assertSame( 317, (int) $post['ID'] );
			$this->assertSame( $type, $post['post_type'] );
			$this->assertSame( 'Edited title', $post['post_title'] );
			$this->assertSame( 'Edited content', $post['post_content'] );
			$this->assertSame( 'draft', $post['post_status'] );
		} else {
			$this->assertSame( 'post_action', $call['command'] );
			$this->assertSame( 317, (int) $call['params']['id'] );
			$this->assertSame( '/delete' === $suffix ? 'delete' : ( 'approve' === $status ? 'publish' : $status ), $call['params']['action'] );
		}
	}

	/**
	 * Pin public success envelopes and the complete GET data shape.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param string            $type     Controller type.
	 * @param string            $suffix   Route suffix.
	 * @param bool              $missing_type Whether Child omitted post_type.
	 */
	private function assert_success( $response, $type, $suffix, $missing_type = false ): void {
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$messages = array(
			''               => 'Get',
			'/edit'          => 'Edit',
			'/delete'        => 'Delete',
			'/update-status' => 'Update',
		);
		$message  = $messages[ $suffix ] . ' ' . $type . ( '/update-status' === $suffix ? ' status' : '' ) . ' successfully.';
		$expected = array(
			'success' => 1,
			'message' => $message,
		);
		if ( '' === $suffix ) {
			$expected['data'] = array(
				'post_password'       => '',
				'ping_status'         => '',
				'comment_status'      => '',
				'post_excerpt'        => '',
				'post_name'           => '',
				'post_date_gmt'       => '',
				'post_date'           => '',
				'post_status'         => 'publish',
				'post_content'        => 'Original content',
				'upload_dir'          => array( 'baseurl' => 'https://child.example/uploads' ),
				'post_custom'         => array( 'fixture' => 'kept' ),
				'post_featured_image' => 'https://child.example/image.jpg',
			);
			if ( 'post' === $type ) {
				$expected['data'] += array(
					'post_category'       => 'News',
					'post_tags'           => '',
					'post_title'          => 'Original title',
					'is_sticky'           => '',
					'post_gallery_images' => array( 23 ),
				);
			} else {
				$expected['data'] += array(
					'id'         => 317,
					'website_id' => (string) $this->site_id,
					'post_type'  => $missing_type ? '' : $type,
				);
			}
		}
		$actual = $response->get_data();
		if ( isset( $expected['data'] ) ) {
			ksort( $expected['data'] );
			ksort( $actual['data'] );
		}
		$this->assertSame( $expected, $actual );
	}
}
