<?php
/**
 * Remote MCP call plan tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Call_Plan
 */
class Test_MainWP_Remote_MCP_Call_Plan extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	public function test_entry_preview_baseline_round_trips_and_is_immutable() {
		$baseline = str_repeat( 'ab', 32 );
		$entry    = array(
			'ability'          => 'mainwp/team-control-delete-role-v1',
			'args'             => array( 'role_id' => 3 ),
			'target_ids'       => null,
			'preview_baseline' => $baseline,
		);
		$plan     = Call_Plan::from_entries( array( $entry ) );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$this->assertSame( $baseline, $plan->entries()[0]['preview_baseline'] );
		$this->assertStringContainsString( '"preview_baseline":"' . $baseline . '"', $plan->to_json() );

		// Kept through storage and compared by equals().
		$restored = Call_Plan::from_json( $plan->to_json() );
		$this->assertTrue( $plan->equals( $restored ) );
		$this->assertSame( $baseline, $restored->entries()[0]['preview_baseline'] );
		$other = Call_Plan::from_entries( array( array_merge( $entry, array( 'preview_baseline' => str_repeat( 'cd', 32 ) ) ) ) );
		$this->assertFalse( $plan->equals( $other ), 'a different baseline is a different plan' );
		$without = Call_Plan::from_entries( array( array_diff_key( $entry, array( 'preview_baseline' => true ) ) ) );
		$this->assertFalse( $plan->equals( $without ) );

		// Optional: absent and null are the same entry, and entries() always names it.
		$this->assertNull( $without->entries()[0]['preview_baseline'] );
		$this->assertTrue( $without->equals( Call_Plan::from_entries( array( array_merge( $entry, array( 'preview_baseline' => null ) ) ) ) ) );
		$this->assertTrue( $without->equals( Call_Plan::from_entries( $without->entries() ) ), 'entries() feeds back unchanged' );
		$this->assertStringNotContainsString( 'preview_baseline', $without->to_json(), 'plans stored before the field still round-trip byte for byte' );

		// Immutable: changing what came back changes nothing.
		$entries                        = $plan->entries();
		$entries[0]['preview_baseline'] = str_repeat( '0', 64 );
		$this->assertSame( $baseline, $plan->entries()[0]['preview_baseline'] );

		// Malformed values make the plan null.
		foreach ( array( '', 'abc', str_repeat( 'AB', 32 ), str_repeat( 'g', 64 ), str_repeat( 'a', 65 ), 123, array( $baseline ), true ) as $bad ) {
			$this->assertNull( Call_Plan::from_entries( array( array_merge( $entry, array( 'preview_baseline' => $bad ) ) ) ), wp_json_encode( $bad ) );
		}
		$this->assertNull( Call_Plan::from_json( '[{"ability":"mainwp/a-v1","args":{},"target_ids":null,"preview_baseline":"zz"}]' ) );
		$this->assertNull( Call_Plan::from_entries( array( array_merge( $entry, array( 'extra' => 1 ) ) ) ), 'still no other keys' );
	}

	public function test_pending_plan_has_one_entry_and_keeps_its_flag_through_json() {
		$entry   = array(
			'ability'    => 'mainwp/delete-client-v1',
			'args'       => array( 'client_id' => 12 ),
			'target_ids' => array( 9, 3 ),
		);
		$pending = Call_Plan::pending( $entry );
		$this->assertInstanceOf( Call_Plan::class, $pending );
		$this->assertTrue( $pending->is_pending() );
		$this->assertSame( 1, $pending->count() );
		$this->assertSame(
			array(
				'ability'          => 'mainwp/delete-client-v1',
				'args'             => array( 'client_id' => 12 ),
				'target_ids'       => array( 3, 9 ),
				'preview_baseline' => null,
			),
			$pending->entries()[0]
		);
		$this->assertSame( array( 3, 9 ), $pending->target_ids() );

		// The same entry built the normal way is executable and a different plan.
		$plan = Call_Plan::from_entries( array( $entry ) );
		$this->assertFalse( $plan->is_pending(), 'from_entries() never sets the flag' );
		$this->assertFalse( $pending->equals( $plan ) );
		$this->assertFalse( $plan->equals( $pending ) );

		// The flag survives storage, and storage can't add it to an executable plan.
		$restored = Call_Plan::from_json( $pending->to_json() );
		$this->assertInstanceOf( Call_Plan::class, $restored );
		$this->assertTrue( $restored->is_pending() );
		$this->assertTrue( $pending->equals( $restored ) );
		$this->assertSame( $pending->to_json(), $restored->to_json() );
		$this->assertFalse( Call_Plan::from_json( $plan->to_json() )->is_pending() );
		$this->assertNull( Call_Plan::from_entries( array( array_merge( $entry, array( 'pending' => true ) ) ) ) );

		// Exactly one well-formed entry with no baseline; anything else is refused.
		foreach ( array(
			'baseline'      => array_merge( $entry, array( 'preview_baseline' => str_repeat( 'ab', 32 ) ) ),
			'null baseline' => array_merge( $entry, array( 'preview_baseline' => null ) ),
			'extra key'     => array_merge( $entry, array( 'extra' => 1 ) ),
			'bad ability'   => array_merge( $entry, array( 'ability' => 'Delete Client' ) ),
			'bad targets'   => array_merge( $entry, array( 'target_ids' => array( 0 ) ) ),
			'a list'        => array( $entry ),
			'two entries'   => array( $entry, $entry ),
		) as $label => $bad ) {
			$this->assertNull( Call_Plan::pending( $bad ), $label );
		}
		$one = substr( $plan->to_json(), 1, -1 );
		foreach ( array(
			'{"entries":[' . $one . ',' . $one . '],"pending":true}',
			'{"entries":[' . $one . '],"pending":false}',
			'{"entries":[' . $one . '],"pending":1}',
			'{"entries":[' . $one . ']}',
			'{"entries":[' . $one . '],"extra":1,"pending":true}',
			'{"entries":[],"pending":true}',
		) as $json ) {
			$this->assertNull( Call_Plan::from_json( $json ), $json );
		}
	}

	public function test_call_plan_is_an_immutable_snapshot_and_round_trips_objects() {
		$selector         = new \stdClass();
		$selector->status = 'active';
		$args             = array(
			'selector' => $selector,
			'items'    => array( 'akismet', 'hello-dolly' ),
			'options'  => new \stdClass(),
		);
		$plan             = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/run-updates-v1',
					'args'       => $args,
					'target_ids' => array( 9, 3 ),
				),
			)
		);
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$frozen = $plan->to_json();

		// Changing what the caller passed in, or what entries() hands out, changes nothing frozen.
		$selector->status       = 'all';
		$selector->extra        = true;
		$args['items'][]        = 'jetpack';
		$entries                = $plan->entries();
		$entries[0]['args']     = array( 'site_ids_or_domains' => array() );
		$entries[0]['ability']  = 'mainwp/delete-site-v1';
		$this->assertSame( $frozen, $plan->to_json() );
		$this->assertSame( 'mainwp/run-updates-v1', $plan->entries()[0]['ability'] );
		$this->assertSame( array( 3, 9 ), $plan->entries()[0]['target_ids'] );
		$this->assertStringContainsString( '"selector":{"status":"active"}', $frozen );
		$this->assertStringContainsString( '"options":{}', $frozen, 'an empty object stays an object' );
		$this->assertStringContainsString( '"items":["akismet","hello-dolly"]', $frozen );

		// JSON objects and lists keep their identity through storage.
		$json     = '[{"ability":"mainwp/run-updates-v1","args":{"selector":{}},"target_ids":null}]';
		$restored = Call_Plan::from_json( $json );
		$this->assertInstanceOf( Call_Plan::class, $restored );
		$this->assertSame( $json, $restored->to_json() );
		$again = Call_Plan::from_json( $restored->to_json() );
		$this->assertTrue( $restored->equals( $again ) );
		$this->assertSame( $restored->to_json(), $again->to_json() );
		$list = Call_Plan::from_json( '[{"ability":"mainwp/run-updates-v1","args":{"selector":[]},"target_ids":null}]' );
		$this->assertFalse( $restored->equals( $list ), '{} and [] are different arguments' );
		$numeric = Call_Plan::from_json( '[{"ability":"mainwp/run-updates-v1","args":{"map":{"0":"a","1":"b"}},"target_ids":null}]' );
		$this->assertStringContainsString( '"map":{"0":"a","1":"b"}', $numeric->to_json(), 'an object with numeric keys stays an object' );
		$this->assertTrue( $plan->equals( Call_Plan::from_json( $plan->to_json() ) ) );

		// Values JSON can't carry are refused, not converted.
		$this->assertNull(
			Call_Plan::from_entries(
				array(
					array(
						'ability'    => 'mainwp/run-updates-v1',
						'args'       => array( 'when' => new \DateTime() ),
						'target_ids' => null,
					),
				)
			)
		);
	}
}
