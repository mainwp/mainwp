<?php
/**
 * Tests for MainWP_Assistant_Policies.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Assistant_Policies;
use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Post_Handler;

/**
 * Test class for MainWP_Assistant_Policies.
 *
 * Tests access modes, protected tags, ability filtering, and policy enforcement.
 */
class Test_Assistant_Policies extends MainWP_Assistant_Test_Case {

	/**
	 * Reset cached abilities after registering test abilities.
	 *
	 * @return void
	 */
	private function reset_policies_ability_cache() {
		$reflection = new \ReflectionProperty( MainWP_Assistant_Policies::class, 'abilities_cache' );
		$reflection->setAccessible( true );
		$reflection->setValue( MainWP_Assistant_Policies::instance(), null );
	}

	/**
	 * Register a temporary MainWP ability for policy tests.
	 *
	 * @param array $annotations Ability annotations.
	 * @return string
	 */
	private function register_test_ability( array $annotations ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API is not available.' );
		}

		$ability_id = 'mainwp/test-policy-' . wp_generate_uuid4() . '-v1';
		wp_register_ability(
			$ability_id,
			array(
				'label'               => 'Test Policy Ability',
				'description'         => 'Temporary test ability.',
				'category'            => 'mainwp-sites',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type' => 'object',
				),
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
				'meta'                => array(
					'annotations' => $annotations,
				),
			)
		);

		$this->reset_policies_ability_cache();

		if ( ! is_object( wp_get_ability( $ability_id ) ) ) {
			$this->markTestSkipped( 'Temporary ability registration is not available in this test environment.' );
		}

		return $ability_id;
	}

	// =========================================================================
	// ACCESS MODE TESTS
	// =========================================================================

	/**
	 * Test get_access_mode returns default when not set.
	 */
	public function test_get_access_mode_returns_default() {
		$user_id = $this->set_current_user_as_admin();

		$mode = MainWP_Assistant_Policies::instance()->get_access_mode( $user_id );

		$this->assertEquals( MainWP_Assistant_Policies::MODE_READ_ONLY, $mode );
	}

	/**
	 * Test set_access_mode stores mode.
	 */
	public function test_set_access_mode() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_access_mode(
			MainWP_Assistant_Policies::MODE_FULL_ACCESS,
			$user_id
		);

		$this->assertTrue( $result );

		$mode = MainWP_Assistant_Policies::instance()->get_access_mode( $user_id );
		$this->assertEquals( MainWP_Assistant_Policies::MODE_FULL_ACCESS, $mode );
	}

	/**
	 * Test set_access_mode treats no-op updates as success.
	 */
	public function test_set_access_mode_returns_true_for_noop_update() {
		$user_id = $this->set_current_user_as_admin();

		update_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_ACCESS_MODE, MainWP_Assistant_Policies::MODE_FULL_ACCESS );

		$result = MainWP_Assistant_Policies::instance()->set_access_mode(
			MainWP_Assistant_Policies::MODE_FULL_ACCESS,
			$user_id
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test set_access_mode fails with invalid mode.
	 */
	public function test_set_access_mode_fails_with_invalid() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_access_mode( 'invalid-mode', $user_id );

		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_invalid_mode' );
	}

	/**
	 * Test set_access_mode rejects unauthorized cross-user writes.
	 */
	public function test_set_access_mode_rejects_unauthorized_cross_user_write() {
		$target_user_id           = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->modified_user_ids[] = $target_user_id;

		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->set_access_mode(
			MainWP_Assistant_Policies::MODE_FULL_ACCESS,
			$target_user_id
		);

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test validate_access_mode validates all modes.
	 */
	public function test_validate_access_mode() {
		$policies = MainWP_Assistant_Policies::instance();

		$this->assertTrue( $policies->validate_access_mode( MainWP_Assistant_Policies::MODE_READ_ONLY ) );
		$this->assertTrue( $policies->validate_access_mode( MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM ) );
		$this->assertTrue( $policies->validate_access_mode( MainWP_Assistant_Policies::MODE_FULL_ACCESS ) );
		$this->assertFalse( $policies->validate_access_mode( 'invalid' ) );
	}

	/**
	 * Test get_access_modes returns all modes with labels.
	 */
	public function test_get_access_modes() {
		$modes = MainWP_Assistant_Policies::instance()->get_access_modes();

		$this->assertIsArray( $modes );
		$this->assertArrayHasKey( MainWP_Assistant_Policies::MODE_READ_ONLY, $modes );
		$this->assertArrayHasKey( MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM, $modes );
		$this->assertArrayHasKey( MainWP_Assistant_Policies::MODE_FULL_ACCESS, $modes );
	}

	/**
	 * Test get_ability_annotations reads annotations from ability objects.
	 */
	public function test_get_ability_annotations_reads_object_meta() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API is not available.' );
		}

		$ability = wp_get_ability( 'mainwp/list-sites-v1' );
		if ( ! is_object( $ability ) ) {
			$this->markTestSkipped( 'MainWP abilities are not registered in this environment.' );
		}

		$annotations = MainWP_Assistant_Policies::instance()->get_ability_annotations( 'mainwp/list-sites-v1' );

		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$annotations
		);
	}

	/**
	 * Test get_ability_annotations falls back to defaults for missing abilities.
	 */
	public function test_get_ability_annotations_returns_defaults_for_missing_ability() {
		if ( method_exists( $this, 'setExpectedIncorrectUsage' ) ) {
			$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		}

		$annotations = MainWP_Assistant_Policies::instance()->get_ability_annotations( 'mainwp/not-a-real-ability-v1' );

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			$annotations
		);
	}

	// =========================================================================
	// PROTECTED TAGS TESTS
	// =========================================================================

	/**
	 * Test get_protected_tags returns empty array when not set.
	 */
	public function test_get_protected_tags_returns_empty_default() {
		$user_id = $this->set_current_user_as_admin();

		$tags = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );

		$this->assertIsArray( $tags );
		$this->assertEmpty( $tags );
	}

	/**
	 * Test set_protected_tags stores tags.
	 */
	public function test_set_protected_tags() {
		$user_id = $this->set_current_user_as_admin();
		$tag_ids = array( 1, 2, 3 );

		$result = MainWP_Assistant_Policies::instance()->set_protected_tags( $tag_ids, $user_id );

		$this->assertTrue( $result );

		$tags = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );
		$this->assertEquals( $tag_ids, $tags );
	}

	/**
	 * Test set_protected_tags rejects unauthorized cross-user writes.
	 */
	public function test_set_protected_tags_rejects_unauthorized_cross_user_write() {
		$target_user_id           = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->modified_user_ids[] = $target_user_id;

		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->set_protected_tags( array( 1, 2 ), $target_user_id );

		$this->assertFalse( $result );
	}

	/**
	 * Test add_protected_tag adds single tag.
	 */
	public function test_add_protected_tag() {
		$user_id = $this->set_current_user_as_admin();

		MainWP_Assistant_Policies::instance()->add_protected_tag( 5, $user_id );
		MainWP_Assistant_Policies::instance()->add_protected_tag( 10, $user_id );

		$tags = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );

		$this->assertCount( 2, $tags );
		$this->assertContains( 5, $tags );
		$this->assertContains( 10, $tags );
	}

	/**
	 * Test add_protected_tag handles duplicate.
	 */
	public function test_add_protected_tag_handles_duplicate() {
		$user_id = $this->set_current_user_as_admin();

		MainWP_Assistant_Policies::instance()->add_protected_tag( 5, $user_id );
		MainWP_Assistant_Policies::instance()->add_protected_tag( 5, $user_id );

		$tags = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );

		$this->assertCount( 1, $tags );
	}

	/**
	 * Test remove_protected_tag removes single tag.
	 */
	public function test_remove_protected_tag() {
		$user_id = $this->set_current_user_as_admin();

		MainWP_Assistant_Policies::instance()->set_protected_tags( array( 1, 2, 3 ), $user_id );
		MainWP_Assistant_Policies::instance()->remove_protected_tag( 2, $user_id );

		$tags = MainWP_Assistant_Policies::instance()->get_protected_tags( $user_id );

		$this->assertCount( 2, $tags );
		$this->assertContains( 1, $tags );
		$this->assertContains( 3, $tags );
		$this->assertNotContains( 2, $tags );
	}

	/**
	 * Test is_tag_protected returns correct result.
	 */
	public function test_is_tag_protected() {
		$user_id = $this->set_current_user_as_admin();

		MainWP_Assistant_Policies::instance()->set_protected_tags( array( 1, 2 ), $user_id );

		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_tag_protected( 1, $user_id ) );
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_tag_protected( 2, $user_id ) );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_tag_protected( 3, $user_id ) );
	}

	// =========================================================================
	// SITE PROTECTED TAGS TESTS
	// =========================================================================

	/**
	 * Test site_has_protected_tags returns empty when no protected tags.
	 */
	public function test_site_has_protected_tags_empty_when_no_protected() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$tag_id  = $this->create_test_tag();

		$this->assign_site_to_tag( $site_id, $tag_id );

		// No protected tags set.
		$result = MainWP_Assistant_Policies::instance()->site_has_protected_tags( $site_id, $user_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test site_has_protected_tags returns matching tags.
	 */
	public function test_site_has_protected_tags_returns_matches() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$tag1_id = $this->create_test_tag( array( 'name' => 'Test Production Tag' ) );
		$tag2_id = $this->create_test_tag( array( 'name' => 'Test Staging Tag' ) );

		$this->assign_site_to_tag( $site_id, $tag1_id );
		$this->assign_site_to_tag( $site_id, $tag2_id );

		// Protect only tag1.
		MainWP_Assistant_Policies::instance()->set_protected_tags( array( $tag1_id ), $user_id );

		$result = MainWP_Assistant_Policies::instance()->site_has_protected_tags( $site_id, $user_id );

		$this->assertCount( 1, $result );
		$this->assertContains( $tag1_id, $result );
	}

	// =========================================================================
	// ABILITY FILTERING TESTS
	// =========================================================================

	/**
	 * Test get_allowed_abilities returns read-only abilities in read-only mode.
	 */
	public function test_get_allowed_abilities_read_only_mode() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$abilities = MainWP_Assistant_Policies::instance()->get_allowed_abilities( $user_id );

		$this->assertContains( 'mainwp/list-sites-v1', $abilities );
		$this->assertContains( 'mainwp/get-site-v1', $abilities );
		$this->assertContains( 'mainwp/count-sites-v1', $abilities );

		// Should NOT contain write abilities.
		$this->assertNotContains( 'mainwp/add-site-v1', $abilities );
		$this->assertNotContains( 'mainwp/delete-site-v1', $abilities );
	}

	/**
	 * Test get_allowed_abilities returns read-write abilities in read-write-confirm mode.
	 */
	public function test_get_allowed_abilities_read_write_mode() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM );

		$abilities = MainWP_Assistant_Policies::instance()->get_allowed_abilities( $user_id );

		// Should contain read-only.
		$this->assertContains( 'mainwp/list-sites-v1', $abilities );

		// Should contain non-destructive write.
		$this->assertContains( 'mainwp/add-site-v1', $abilities );
		$this->assertContains( 'mainwp/update-site-v1', $abilities );
		$this->assertContains( 'mainwp/sync-sites-v1', $abilities );

		// Should NOT contain destructive.
		$this->assertNotContains( 'mainwp/delete-site-v1', $abilities );
		$this->assertNotContains( 'mainwp/disconnect-site-v1', $abilities );
	}

	/**
	 * Test read-only mode includes dynamically registered readonly abilities.
	 */
	public function test_get_allowed_abilities_includes_dynamic_readonly_ability() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$ability_id = $this->register_test_ability(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$abilities = MainWP_Assistant_Policies::instance()->get_allowed_abilities( $user_id );

		$this->assertContains( $ability_id, $abilities );
	}

	/**
	 * Test the hardcoded fallback groups stay in sync with registered abilities.
	 *
	 * classify_ability() consults the fallback list before live annotations, so
	 * the fallback placement is the effective policy for listed abilities. Two
	 * invariants: every registered core ability must appear in some fallback
	 * group (a missing ability silently loses its intended policy tier when the
	 * fallback path is used), and no ability may sit in a LESS restrictive
	 * group than its annotations map to. Deliberate escalation is allowed —
	 * suspend/disconnect abilities are annotated non-destructive but pinned in
	 * the destructive group so they always require confirmation.
	 */
	public function test_fallback_ability_groups_match_registered_annotations() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available.' );
		}

		$policies = MainWP_Assistant_Policies::instance();

		$fallback_method = new \ReflectionMethod( MainWP_Assistant_Policies::class, 'get_fallback_ability_groups' );
		$fallback_method->setAccessible( true );
		$fallback = $fallback_method->invoke( $policies );

		$annotations_method = new \ReflectionMethod( MainWP_Assistant_Policies::class, 'get_registered_ability_annotations' );
		$annotations_method->setAccessible( true );

		$severity = array(
			'readonly'        => 0,
			'non_destructive' => 1,
			'destructive'     => 2,
		);

		$checked = 0;
		$drifted = array();

		foreach ( array_keys( wp_get_abilities() ) as $ability_id ) {
			if ( 0 !== strpos( $ability_id, 'mainwp/' ) ) {
				continue;
			}

			// Temporary abilities registered by other tests in this suite.
			if ( 0 === strpos( $ability_id, 'mainwp/test-policy-' ) ) {
				continue;
			}

			$annotations = $annotations_method->invoke( $policies, $ability_id );

			$actual = null;
			foreach ( $severity as $group => $unused ) {
				if ( in_array( $ability_id, $fallback[ $group ], true ) ) {
					$actual = $group;
					break;
				}
			}

			++$checked;

			// Without annotations the fallback list is the only classifier;
			// classify_ability() drops an unlisted annotationless ability
			// from every group, so presence in some group is mandatory.
			if ( ! is_array( $annotations ) ) {
				if ( null === $actual ) {
					$drifted[] = $ability_id . ' (no annotations; must be listed in a fallback group)';
				}
				continue;
			}

			// Same mapping as classify_ability().
			if ( ! empty( $annotations['readonly'] ) ) {
				$expected = 'readonly';
			} elseif ( ! empty( $annotations['destructive'] ) ) {
				$expected = 'destructive';
			} else {
				$expected = 'non_destructive';
			}

			if ( null === $actual ) {
				$drifted[] = $ability_id . ' (missing; add to group: ' . $expected . ')';
			} elseif ( $severity[ $actual ] < $severity[ $expected ] ) {
				$drifted[] = $ability_id . ' (in ' . $actual . ', annotations require at least ' . $expected . ')';
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No registered MainWP abilities found to compare against the fallback groups.' );
		$this->assertSame(
			array(),
			$drifted,
			'get_fallback_ability_groups() drifted from registered ability annotations: ' . implode( ', ', $drifted )
		);
	}

	/**
	 * Test the availability flags have a single runtime write path.
	 *
	 * mainwp_assistant_enabled and mainwp_enable_guided_chatbase must always
	 * agree, so every runtime write goes through persist_availability_flags().
	 * A direct update_option() elsewhere can let the two flags drift (the
	 * help-modal handler used to re-write the chatbase flag after
	 * set_assistant_enabled() had already mirrored it). Install seeding in
	 * class-mainwp-install.php reconciles the legacy flag on upgrade and
	 * intentionally stays outside the writer.
	 */
	public function test_availability_flags_have_single_write_path() {
		$writer       = new \ReflectionMethod( MainWP_Assistant_Policies::class, 'persist_availability_flags' );
		$writer_file  = $writer->getFileName();
		$writer_start = $writer->getStartLine();
		$writer_end   = $writer->getEndLine();

		$files = array(
			( new \ReflectionClass( MainWP_Assistant_Policies::class ) )->getFileName(),
			( new \ReflectionClass( MainWP_Post_Handler::class ) )->getFileName(),
		);

		// Catches update_option() and MainWP_Utility::update_option() with
		// either flag, named directly or through the class constant.
		$pattern = '/update_option\(\s*(?:self::OPTION_ASSISTANT_ENABLED|MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED|\'mainwp_assistant_enabled\'|\'mainwp_enable_guided_chatbase\')/';

		$inside     = 0;
		$violations = array();

		foreach ( $files as $file ) {
			$lines = file( $file );
			$this->assertNotFalse( $lines, 'Failed to read ' . $file );

			foreach ( $lines as $index => $line ) {
				if ( ! preg_match( $pattern, $line ) ) {
					continue;
				}

				$line_number = $index + 1;
				if ( $file === $writer_file && $line_number >= $writer_start && $line_number <= $writer_end ) {
					++$inside;
					continue;
				}

				$violations[] = basename( $file ) . ':' . $line_number;
			}
		}

		$this->assertSame( 2, $inside, 'persist_availability_flags() must write both availability flags.' );
		$this->assertSame(
			array(),
			$violations,
			'Availability flags written outside persist_availability_flags(): ' . implode( ', ', $violations )
		);
	}

	/**
	 * Test is_ability_allowed returns correct result.
	 */
	public function test_is_ability_allowed() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_ability_allowed( 'mainwp/list-sites-v1', $user_id ) );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_ability_allowed( 'mainwp/delete-site-v1', $user_id ) );
	}

	/**
	 * Test filter_abilities_by_mode filters array correctly.
	 */
	public function test_filter_abilities_by_mode() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$input = array(
			'mainwp/list-sites-v1',
			'mainwp/delete-site-v1',
			'mainwp/get-site-v1',
			'mainwp/add-site-v1',
		);

		$filtered = MainWP_Assistant_Policies::instance()->filter_abilities_by_mode( $input, $user_id );

		$this->assertCount( 2, $filtered );
		$this->assertContains( 'mainwp/list-sites-v1', $filtered );
		$this->assertContains( 'mainwp/get-site-v1', $filtered );
	}

	// =========================================================================
	// GLOBAL ASSISTANT TOGGLE TESTS
	// =========================================================================

	/**
	 * Test is_assistant_enabled returns false when no assistant options exist.
	 */
	public function test_is_assistant_enabled_default() {
		delete_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED );
		delete_option( 'mainwp_enable_guided_chatbase' );

		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	/**
	 * Test install/update seeding copies the legacy assistant setting when needed.
	 */
	public function test_install_seeds_assistant_enabled_from_legacy_option_when_missing() {
		delete_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED );
		update_option( 'mainwp_enable_guided_chatbase', 1 );
		update_site_option( 'mainwp_db_version', '9.0.1.5' );

		MainWP_Install::instance()->install();

		$this->assertTrue( (bool) get_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, false ) );
	}

	/**
	 * Test install/update seeding disables the assistant on upgrades when no prior setting exists.
	 */
	public function test_install_seeds_assistant_enabled_false_on_upgrade_when_missing() {
		delete_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED );
		delete_option( 'mainwp_enable_guided_chatbase' );
		update_site_option( 'mainwp_db_version', '9.0.1.5' );

		MainWP_Install::instance()->install();

		$this->assertFalse( (bool) get_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, true ) );
	}

	/**
	 * Test set_assistant_enabled toggles state.
	 *
	 * Uses helper method because WordPress's update_option has issues with boolean false -
	 * it may not create the option. The helper uses integer 0/1 instead.
	 */
	public function test_set_assistant_enabled() {
		$this->set_current_user_as_admin();

		// Ensure clean state.
		delete_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED );

		// Use helper to set disabled state (uses integer 0 internally).
		$this->set_assistant_enabled( false );
		$this->assertFalse( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );

		// Use helper to set enabled state (uses integer 1 internally).
		$this->set_assistant_enabled( true );
		$this->assertTrue( MainWP_Assistant_Policies::instance()->is_assistant_enabled() );
	}

	/**
	 * Test set_assistant_enabled treats no-op updates as success.
	 */
	public function test_set_assistant_enabled_returns_true_for_noop_update() {
		$this->set_current_user_as_admin();

		update_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, true );
		update_option( 'mainwp_enable_guided_chatbase', 1 );

		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( true );

		$this->assertTrue( $result );
		$this->assertTrue( (bool) get_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, false ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) );
	}

	/**
	 * Test set_assistant_enabled requires manage_options.
	 */
	public function test_set_assistant_enabled_requires_capability() {
		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( false );

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test revoke_consent disables assistant flags as well as consent.
	 */
	public function test_revoke_consent_disables_assistant_flags() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', 'test-token' );

		$result = MainWP_Assistant_Policies::instance()->revoke_consent();

		$this->assertTrue( $result );
		$this->assertFalse( (bool) get_option( MainWP_Assistant_Policies::OPTION_CONSENT, true ) );
		$this->assertFalse( (bool) get_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, true ) );
		$this->assertSame( 0, (int) get_option( 'mainwp_enable_guided_chatbase', 1 ) );
		$this->assertEmpty( MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_assistant_bridge_token', '' ) );
	}

	/**
	 * Test grant_consent enables both assistant flags, mirroring how
	 * revoke_consent disables both. The legacy chatbase flag feeds REST
	 * tool-settings and Server Information, so it must track feature state.
	 */
	public function test_grant_consent_enables_assistant_flags() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );
		$this->set_assistant_consent( false );
		update_option( 'mainwp_enable_guided_chatbase', 0 );

		$result = MainWP_Assistant_Policies::instance()->grant_consent();

		$this->assertTrue( $result );
		$this->assertTrue( (bool) get_option( MainWP_Assistant_Policies::OPTION_CONSENT, false ) );
		$this->assertTrue( (bool) get_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, false ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enable_guided_chatbase', 0 ) );
	}

	/**
	 * Test grant_consent requires manage_options.
	 */
	public function test_grant_consent_requires_capability() {
		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->grant_consent();

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test revoke_consent leaves conversation history in place. Disabling the
	 * feature is decoupled from purging data, which happens only through the
	 * Clear History action.
	 */
	public function test_revoke_consent_preserves_conversations() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Survives revoke' );
		$this->add_test_message( $conversation_id, 'user', 'Hello' );
		$this->assertSame( 1, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );

		$result = MainWP_Assistant_Policies::instance()->revoke_consent();

		$this->assertTrue( $result );
		$this->assertFalse( (bool) get_option( MainWP_Assistant_Policies::OPTION_CONSENT, true ) );
		$this->assertSame( 1, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );
	}

	// =========================================================================
	// POLICY ENFORCEMENT TESTS
	// =========================================================================

	/**
	 * Test check_policy_allows returns error when assistant disabled.
	 */
	public function test_check_policy_allows_fails_when_disabled() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( false );

		$result = MainWP_Assistant_Policies::instance()->check_policy_allows(
			'mainwp/list-sites-v1',
			null,
			$user_id
		);

		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_disabled' );
	}

	/**
	 * Test check_policy_allows returns error for disallowed ability.
	 */
	public function test_check_policy_allows_fails_for_disallowed_ability() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$result = MainWP_Assistant_Policies::instance()->check_policy_allows(
			'mainwp/delete-site-v1',
			null,
			$user_id
		);

		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_ability_not_allowed' );
	}

	/**
	 * Test check_policy_allows returns true for allowed ability.
	 */
	public function test_check_policy_allows_succeeds_for_allowed_ability() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );

		$result = MainWP_Assistant_Policies::instance()->check_policy_allows(
			'mainwp/list-sites-v1',
			null,
			$user_id
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test check_policy_allows requires confirmation for protected site.
	 */
	public function test_check_policy_allows_requires_confirmation_for_protected() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$tag_id  = $this->create_test_tag( array( 'name' => 'Test Protected Tag' ) );

		$this->assign_site_to_tag( $site_id, $tag_id );
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->set_user_protected_tags( $user_id, array( $tag_id ) );

		$result = MainWP_Assistant_Policies::instance()->check_policy_allows(
			'mainwp/delete-site-v1',
			$site_id,
			$user_id
		);

		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_confirmation_required' );
	}

	/**
	 * Test check_policy_allows allows confirmed protected site.
	 */
	public function test_check_policy_allows_confirmed_protected_site() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$tag_id  = $this->create_test_tag( array( 'name' => 'Test Protected Tag' ) );

		$this->assign_site_to_tag( $site_id, $tag_id );
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->set_user_protected_tags( $user_id, array( $tag_id ) );

		// Pass confirmation in input.
		$input = array(
			'confirmed_protected_sites' => array( $site_id ),
		);

		$result = MainWP_Assistant_Policies::instance()->check_policy_allows(
			'mainwp/delete-site-v1',
			$site_id,
			$user_id,
			$input
		);

		$this->assertTrue( $result );
	}

	// =========================================================================
	// DESTRUCTIVE ABILITIES TESTS
	// =========================================================================

	/**
	 * Test requires_confirmation identifies destructive abilities.
	 */
	public function test_requires_confirmation() {
		$policies = MainWP_Assistant_Policies::instance();

		$this->assertTrue( $policies->requires_confirmation( 'mainwp/delete-site-v1' ) );
		$this->assertTrue( $policies->requires_confirmation( 'mainwp/disconnect-site-v1' ) );
		$this->assertTrue( $policies->requires_confirmation( 'mainwp/suspend-site-v1' ) );

		$this->assertFalse( $policies->requires_confirmation( 'mainwp/list-sites-v1' ) );
		$this->assertFalse( $policies->requires_confirmation( 'mainwp/add-site-v1' ) );
	}

	/**
	 * Test get_destructive_abilities returns list.
	 */
	public function test_get_destructive_abilities() {
		$destructive = MainWP_Assistant_Policies::instance()->get_destructive_abilities();

		$this->assertIsArray( $destructive );
		$this->assertContains( 'mainwp/delete-site-v1', $destructive );
		$this->assertContains( 'mainwp/delete-client-v1', $destructive );
		$this->assertContains( 'mainwp/delete-tag-v1', $destructive );
	}

	/**
	 * Test requires_confirmation includes dynamically registered destructive abilities.
	 */
	public function test_requires_confirmation_includes_dynamic_destructive_ability() {
		$ability_id = $this->register_test_ability(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->assertTrue( MainWP_Assistant_Policies::instance()->requires_confirmation( $ability_id ) );
	}

	// =========================================================================
	// CONFIRMATION LEVEL TESTS
	// =========================================================================

	/**
	 * Test get_confirmation_level returns default 'all' when not set.
	 */
	public function test_get_confirmation_level_defaults_to_all() {
		$user_id = $this->set_current_user_as_admin();

		$level = MainWP_Assistant_Policies::instance()->get_confirmation_level( $user_id );

		$this->assertEquals( MainWP_Assistant_Policies::CONFIRM_ALL, $level );
	}

	/**
	 * Test set_confirmation_level stores and retrieves 'destructive_only'.
	 */
	public function test_set_confirmation_level() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level(
			MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE,
			$user_id
		);

		$this->assertTrue( $result );

		$level = MainWP_Assistant_Policies::instance()->get_confirmation_level( $user_id );
		$this->assertEquals( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $level );
	}

	/**
	 * Test set_confirmation_level treats no-op updates as success.
	 */
	public function test_set_confirmation_level_returns_true_for_noop_update() {
		$user_id = $this->set_current_user_as_admin();

		update_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_CONFIRMATION_LEVEL, MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE );

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level(
			MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE,
			$user_id
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test set_confirmation_level rejects unauthorized cross-user writes.
	 */
	public function test_set_confirmation_level_rejects_unauthorized_cross_user_write() {
		$target_user_id           = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->modified_user_ids[] = $target_user_id;

		$this->set_current_user_as_subscriber();

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level(
			MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE,
			$target_user_id
		);

		$this->assertWPErrorWithCode( $result, 'mainwp_permission_denied' );
	}

	/**
	 * Test set_confirmation_level rejects invalid values.
	 */
	public function test_set_confirmation_level_rejects_invalid() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_Assistant_Policies::instance()->set_confirmation_level( 'invalid-level', $user_id );

		$this->assertWPErrorWithCode( $result, 'mainwp_assistant_invalid_confirmation_level' );
	}

	// =========================================================================
	// HELPER METHOD TESTS
	// =========================================================================

	/**
	 * Test get_policy_summary returns correct structure.
	 */
	public function test_get_policy_summary() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM );
		$this->set_user_protected_tags( $user_id, array( 1, 2, 3 ) );

		$summary = MainWP_Assistant_Policies::instance()->get_policy_summary( $user_id );

		$this->assertIsArray( $summary );
		$this->assertTrue( $summary['assistant_enabled'] );
		$this->assertEquals( MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM, $summary['access_mode'] );
		$this->assertEquals( array( 1, 2, 3 ), $summary['protected_tags'] );
		$this->assertIsInt( $summary['allowed_abilities'] );
	}
}
