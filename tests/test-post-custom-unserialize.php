<?php
/**
 * Bulk post/page custom-field deserialization safety (self-audit follow-up, item 2).
 *
 * The bulk post and bulk page senders expand the local draft's serialized
 * post-meta before base64/JSON encoding it for the child site. That expansion
 * must pass allowed_classes=>false, so a serialized object sitting in wp_postmeta
 * cannot instantiate a class -- and run its __wakeup()/__destruct() -- on the
 * Dashboard during the send.
 *
 * Nothing downstream reads these values as objects: they terminate at
 * wp_json_encode(), so refusing to build the object costs the feature nothing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * A class with an observable side effect on unserialize. If expand_post_custom()
 * ever instantiates it, __wakeup() flips the flag and the test fails.
 */
class Post_Custom_Gadget {

	/**
	 * Set by __wakeup() when PHP actually builds this class.
	 *
	 * @var bool
	 */
	public static $woke = false;

	/**
	 * Marker property carried through serialization.
	 *
	 * @var string
	 */
	public $payload = 'inert';

	/**
	 * Records that PHP instantiated this class from a serialized string.
	 */
	public function __wakeup() {
		static::$woke = true;
	}
}

/**
 * Carries a private and a protected property. json_encode() hides both for a real
 * object; they must stay hidden after the incomplete-class round trip too.
 */
class Post_Custom_Private {

	/**
	 * Public, and the only property the child should ever see.
	 *
	 * @var string
	 */
	public $pub = 'p';

	/**
	 * Private, and must never reach the wire.
	 *
	 * @var string
	 */
	private $secret = 'SHHH';

	/**
	 * Protected, and must never reach the wire.
	 *
	 * @var string
	 */
	protected $prot = 'q';
}

class Test_Post_Custom_Unserialize extends \WP_UnitTestCase {

	/**
	 * Reset the gadget's tripwire between tests.
	 */
	public function set_up() {
		parent::set_up();
		Post_Custom_Gadget::$woke = false;
	}

	/**
	 * Shape the argument the way get_post_custom() returns it: meta key => list
	 * of raw meta values.
	 *
	 * @param string $value Raw meta value.
	 * @return array
	 */
	private function meta( string $value ): array {
		return array( 'a_meta_key' => array( $value ) );
	}

	/**
	 * The bytes the child actually receives. Both senders ship post_custom as
	 * base64( wp_json_encode( ... ) ), and that encode step is where an
	 * incomplete-class placeholder would leak its marker property, so the
	 * assertions that matter live on this side of it.
	 *
	 * @param array $post_custom Meta as get_post_custom() returns it.
	 * @return string Decoded JSON exactly as the child would parse it.
	 */
	private function wire( array $post_custom ): string {
		return base64_decode( base64_encode( wp_json_encode( MainWP_Utility::expand_post_custom( $post_custom ) ) ) );
	}

	/**
	 * A serialized object must not be instantiated, and must not drag a
	 * __PHP_Incomplete_Class_Name key onto the wire in place of the class.
	 */
	public function test_serialized_object_is_not_instantiated() {
		$serialized = serialize( new Post_Custom_Gadget() );

		$wire = $this->wire( $this->meta( $serialized ) );

		$this->assertFalse(
			Post_Custom_Gadget::$woke,
			'expand_post_custom() instantiated a class from post meta; allowed_classes=>false is missing.'
		);
		$this->assertSame( '{"a_meta_key":[{"payload":"inert"}]}', $wire );
		$this->assertStringNotContainsString( '__PHP_Incomplete_Class', $wire );
	}

	/**
	 * The same guard has to hold for an object nested inside an otherwise ordinary
	 * array, which is the shape real post meta takes.
	 */
	public function test_object_nested_in_an_array_is_not_instantiated() {
		$serialized = serialize(
			array(
				'label'  => 'ok',
				'gadget' => new Post_Custom_Gadget(),
			)
		);

		$wire = $this->wire( $this->meta( $serialized ) );

		$this->assertFalse(
			Post_Custom_Gadget::$woke,
			'expand_post_custom() instantiated a nested class from post meta.'
		);
		$this->assertSame( '{"a_meta_key":[{"label":"ok","gadget":{"payload":"inert"}}]}', $wire );
		$this->assertStringNotContainsString( '__PHP_Incomplete_Class', $wire );
	}

	/**
	 * A plain stdClass in post meta is the ordinary case, and it has to reach the
	 * child encoded exactly as it was before the hardening: {"x":1}, no marker.
	 */
	public function test_stdclass_reaches_the_child_unchanged() {
		$serialized = serialize( (object) array( 'x' => 1 ) );

		$this->assertSame(
			'{"a_meta_key":[{"x":1}]}',
			$this->wire( $this->meta( $serialized ) )
		);
	}

	/**
	 * Private and protected properties survive unserialize under NUL-mangled
	 * names. json_encode() would have dropped them for a real object, so they must
	 * not be forwarded to the child either.
	 */
	public function test_private_and_protected_properties_are_not_sent_to_the_child() {
		$serialized = serialize( new Post_Custom_Private() );

		$wire = $this->wire( $this->meta( $serialized ) );

		$this->assertSame( '{"a_meta_key":[{"pub":"p"}]}', $wire );
		$this->assertStringNotContainsString( 'SHHH', $wire );
	}

	/**
	 * The feature itself: a normal serialized array still round-trips intact.
	 */
	public function test_serialized_array_still_round_trips() {
		$value = array(
			'size'  => 'large',
			'tags'  => array( 'one', 'two' ),
			'count' => 3,
		);

		$expanded = MainWP_Utility::expand_post_custom( $this->meta( serialize( $value ) ) );

		$this->assertSame( $value, $expanded['a_meta_key'][0] );
	}

	/**
	 * A plain scalar meta value is not serialized, so it passes through untouched.
	 */
	public function test_unserialized_scalar_passes_through_untouched() {
		$expanded = MainWP_Utility::expand_post_custom( $this->meta( 'just a string' ) );

		$this->assertSame( 'just a string', $expanded['a_meta_key'][0] );
	}

	/**
	 * Keys and the meta-key => values shape must survive, since the child site is
	 * handed this array verbatim as JSON.
	 */
	public function test_meta_key_and_value_keys_are_preserved() {
		$post_custom = array(
			'_edit_lock' => array( '1699999999:1' ),
			'a_list'     => array(
				'first'  => serialize( array( 'x' => 1 ) ),
				'second' => 'plain',
			),
		);

		$expanded = MainWP_Utility::expand_post_custom( $post_custom );

		$this->assertSame( array( '_edit_lock', 'a_list' ), array_keys( $expanded ) );
		$this->assertSame( array( 'first', 'second' ), array_keys( $expanded['a_list'] ) );
		$this->assertSame( array( 'x' => 1 ), $expanded['a_list']['first'] );
		$this->assertSame( 'plain', $expanded['a_list']['second'] );
	}

	/**
	 * get_post_custom() returns false when a post has no meta at all, and both
	 * callers pass its return value straight through.
	 */
	public function test_non_array_input_yields_an_empty_array() {
		$this->assertSame( array(), MainWP_Utility::expand_post_custom( false ) );
		$this->assertSame( array(), MainWP_Utility::expand_post_custom( null ) );
	}
}
