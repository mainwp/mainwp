<?php
/**
 * Bulk post custom field unserialize tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Page;
use MainWP\Dashboard\MainWP_Post_Page_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Bulk posting sends the bulk post's custom fields to each Child. Reading
 * them back must not build objects on the Dashboard.
 */
class Test_Bulk_Post_Custom_Field_Unserialize extends \WP_UnitTestCase {

	/**
	 * The post_custom payload the posting path handed to the Child request.
	 *
	 * @var array|null
	 */
	private $sent_custom = null;

	/**
	 * Load the handlers that run bulk post and bulk page posting.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/pages/page-mainwp-post-page-handler.php';
		require_once dirname( __DIR__ ) . '/pages/page-mainwp-page.php';
	}

	/**
	 * Reset the recorder and capture the outgoing request before curl runs.
	 */
	public function set_up(): void {
		parent::set_up();
		Bulk_Post_Wakeup_Recorder::$wakeups = 0;
		$this->sent_custom                  = null;
		add_filter( 'mainwp_pre_posting_posts', array( $this, 'capture_and_stop' ), 10, 1 );
	}

	/**
	 * Remove the capture filter.
	 */
	public function tear_down(): void {
		remove_filter( 'mainwp_pre_posting_posts', array( $this, 'capture_and_stop' ), 10 );
		unset( $_GET['id'], $_GET['posting_nonce'] );
		parent::tear_down();
	}

	/**
	 * Keep the payload and stop the request so the test never reaches the network.
	 *
	 * @param array $params Request parameters.
	 *
	 * @throws \RuntimeException Always, once the payload is captured.
	 */
	public function capture_and_stop( $params ) {
		$this->sent_custom = json_decode( base64_decode( $params['post_custom'] ), true );
		throw new \RuntimeException( 'bulk-post-captured' );
	}

	/**
	 * Create a bulk post aimed at one connected site.
	 *
	 * @return int Bulk post ID.
	 */
	private function bulk_post_for_one_site() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$site_id = MainWP_DB::instance()->add_website(
			$user_id,
			'Bulk Post Target',
			'http://bulk-post-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => '',
				'http_pass' => '',
			)
		);
		$this->assertNotFalse( $site_id );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Bulk post' ) );
		update_post_meta( $post_id, '_selected_by', 'site' );
		update_post_meta( $post_id, '_selected_sites', array( (string) $site_id ) );

		return $post_id;
	}

	/**
	 * Run the bulk posting until the Child request would be sent.
	 *
	 * @param int  $post_id Bulk post ID.
	 * @param bool $as_page Post through the bulk page path instead.
	 */
	private function post_to_child( $post_id, $as_page = false ) {
		ob_start();
		try {
			if ( $as_page ) {
				$_GET['id']            = $post_id;
				$_GET['posting_nonce'] = wp_create_nonce( 'posting_nonce_' . $post_id );
				MainWP_Page::posting();
			} else {
				MainWP_Post_Page_Handler::posting_posts( $post_id, 'posting' );
			}
			$this->fail( 'The posting path never reached the Child request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bulk-post-captured', $e->getMessage() );
		} finally {
			ob_end_clean();
		}
		$this->assertIsArray( $this->sent_custom );
	}

	/**
	 * A custom field stored as an object must not be woken on the Dashboard.
	 */
	public function test_object_custom_field_is_not_instantiated(): void {
		$post_id = $this->bulk_post_for_one_site();
		// Code that writes an object to post meta stores the bare O: string.
		add_post_meta( $post_id, 'gadget', new Bulk_Post_Wakeup_Recorder() );
		$this->assertStringStartsWith( 'O:', get_post_custom( $post_id )['gadget'][0] );

		$this->post_to_child( $post_id );

		$this->assertSame( 0, Bulk_Post_Wakeup_Recorder::$wakeups );
		$this->assertSame(
			Bulk_Post_Wakeup_Recorder::class,
			$this->sent_custom['gadget'][0]['__PHP_Incomplete_Class_Name']
		);
	}

	/**
	 * Bulk page posting reads the same custom fields and must not wake objects either.
	 */
	public function test_page_object_custom_field_is_not_instantiated(): void {
		$post_id = $this->bulk_post_for_one_site();
		add_post_meta( $post_id, 'gadget', new Bulk_Post_Wakeup_Recorder() );
		add_post_meta( $post_id, 'settings', array( 'layout' => 'wide' ) );

		$this->post_to_child( $post_id, true );

		$this->assertSame( 0, Bulk_Post_Wakeup_Recorder::$wakeups );
		$this->assertSame( array( 'layout' => 'wide' ), $this->sent_custom['settings'][0] );
	}

	/**
	 * Every input shape the selection decoder sees, and what it must return.
	 */
	public function test_selection_decoder_matrix(): void {
		$ids    = array( '3', '5' );
		$object = serialize( new Bulk_Post_Wakeup_Recorder() );
		$cases  = array(
			'empty string'           => array( '', '' ),
			'array'                  => array( $ids, $ids ),
			'serialized array'       => array( serialize( $ids ), $ids ),
			'base64 serialized'      => array( base64_encode( serialize( $ids ) ), $ids ),
			'serialized scalar'      => array( serialize( 'text' ), 'text' ),
			'base64 plain text'      => array( base64_encode( 'text' ), 'text' ),
			'nested array'           => array( serialize( array( 'a' => $ids ) ), array( 'a' => $ids ) ),
			'serialized object'      => array( $object, null ),
			'base64 object'          => array( base64_encode( $object ), null ),
			'object inside an array' => array( serialize( array( new Bulk_Post_Wakeup_Recorder() ) ), null ),
		);

		foreach ( $cases as $label => $case ) {
			$decoded = MainWP_System_Utility::maybe_unserialyze( $case[0] );
			if ( null !== $case[1] ) {
				$this->assertSame( $case[1], $decoded, $label );
			} else {
				$this->assertStringContainsString( '__PHP_Incomplete_Class_Name', wp_json_encode( $decoded ), $label );
			}
		}
		$this->assertSame( 0, Bulk_Post_Wakeup_Recorder::$wakeups );
	}

	/**
	 * A Child post imported for editing can set _selected_groups on the local draft; posting it must not wake an object.
	 */
	public function test_child_supplied_selected_groups_is_not_instantiated(): void {
		$object = serialize( new Bulk_Post_Wakeup_Recorder() );
		foreach ( array( $object, base64_encode( $object ) ) as $stored ) {
			$post_id = $this->bulk_post_for_one_site();
			// create_post() stores what the Child sent with update_post_meta(); slashed so the namespace backslashes survive wp_unslash().
			update_post_meta( $post_id, '_selected_groups', wp_slash( $stored ) );

			$this->post_to_child( $post_id );
			$this->post_to_child( $post_id, true );
		}

		$this->assertSame( 0, Bulk_Post_Wakeup_Recorder::$wakeups );
	}

	/**
	 * Arrays, scalars and typed text still reach the Child unchanged.
	 */
	public function test_arrays_and_scalars_round_trip(): void {
		$post_id = $this->bulk_post_for_one_site();
		$array   = array(
			'layout' => 'wide',
			'ids'    => array( 3, 5 ),
			'flag'   => true,
		);
		add_post_meta( $post_id, 'settings', $array );
		add_post_meta( $post_id, 'subtitle', 'Plain text' );
		add_post_meta( $post_id, 'count', 7 );
		// A value typed into the custom field box is serialized once more on save, so it stays text.
		add_post_meta( $post_id, 'typed', 'O:8:"stdClass":1:{s:1:"a";i:1;}' );

		$this->post_to_child( $post_id );

		$this->assertSame( $array, $this->sent_custom['settings'][0] );
		$this->assertSame( 'Plain text', $this->sent_custom['subtitle'][0] );
		$this->assertSame( '7', $this->sent_custom['count'][0] );
		$this->assertSame( 'O:8:"stdClass":1:{s:1:"a";i:1;}', $this->sent_custom['typed'][0] );
		$this->assertSame( array( 'site' ), $this->sent_custom['_selected_by'] );
	}
}

/**
 * Counts how often PHP wakes an instance, so a test can see whether
 * unserialize() built one.
 */
class Bulk_Post_Wakeup_Recorder {

	/**
	 * Number of __wakeup() calls.
	 *
	 * @var int
	 */
	public static $wakeups = 0;

	/**
	 * Record the wakeup.
	 */
	public function __wakeup() {
		++self::$wakeups;
	}
}
