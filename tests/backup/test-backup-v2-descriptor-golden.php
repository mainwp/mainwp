<?php
/**
 * Descriptor bytes produced by Child PR #123 at d96a7b6d.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The encoder observer belongs to this fixture.

/**
 * Observe the encoder flags while preserving WordPress encoding behavior.
 *
 * @param mixed $value JSON value.
 * @param int   $flags JSON flags.
 * @param int   $depth Maximum depth.
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	if ( null !== MainWP_Backup_V2_Descriptor_Golden_Test::$encoding_flags ) {
		MainWP_Backup_V2_Descriptor_Golden_Test::$encoding_flags[] = $flags;
	}
	return \wp_json_encode( $value, $flags, $depth );
}

/** Pins the Dashboard reader to the Child's literal output. */
class MainWP_Backup_V2_Descriptor_Golden_Test extends \WP_UnitTestCase {
	/**
	 * Observed flags; disabled outside the encoder test.
	 *
	 * @var array|null
	 */
	public static $encoding_flags = null;

	/**
	 * Load the portable descriptor cases shared with the Child suite.
	 *
	 * @return array
	 */
	public static function shared_descriptor_cases() {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/mwp2-descriptor-contract.json' ), true, 512, JSON_THROW_ON_ERROR );
		$cases   = array();
		foreach ( $fixture['cases'] as $fixture_case ) {
			$cases[ $fixture_case['name'] ] = array( $fixture_case );
		}
		return $cases;
	}

	/**
	 * Build an envelope from the shared canonical control.
	 *
	 * @return array
	 */
	private function metadata() {
		$fixture_case = self::shared_descriptor_cases()['canonical_control'][0];
		return array(
			'backup_format'            => 2,
			'layout_descriptor'        => $fixture_case['input_descriptor'],
			'layout_descriptor_sha256' => $fixture_case['supplied_digest'],
		);
	}

	/**
	 * Every shared fixture passes through the real Dashboard validator.
	 *
	 * @dataProvider shared_descriptor_cases
	 * @param array $fixture_case Portable contract case.
	 */
	public function test_child_metadata_bytes_and_observation( array $fixture_case ) {
		$this->assertSame( $fixture_case['canonical_sha256'], hash( 'sha256', $fixture_case['canonical_bytes'] ) );
		$descriptor = $fixture_case['input_descriptor'];
		if ( isset( $fixture_case['metadata_bytes'] ) ) {
			$descriptor = json_decode( $fixture_case['metadata_bytes'], true );
			$this->assertSame( $fixture_case['input_descriptor'], $descriptor );
		}
		$result = MainWP_Backup_Snapshots::validate_format_metadata(
			array(
				'backup_format'            => $fixture_case['backup_format'],
				'layout_descriptor'        => $descriptor,
				'layout_descriptor_sha256' => $fixture_case['supplied_digest'],
			)
		);
		if ( 'refuse' === $fixture_case['expected_result'] ) {
			$this->assertWPError( $result );
			$this->assertSame( $fixture_case['error_code'], $result->get_error_code() );
			return;
		}
		$this->assertFalse( is_wp_error( $result ) );
		$expected = json_decode( $fixture_case['canonical_bytes'], true );
		if ( array_key_exists( 'core_version', $descriptor ) ) {
			$expected['core_version'] = $descriptor['core_version'];
		}
		$this->assertSame( $expected, $result['layout_descriptor'] );
		$this->assertSame( $fixture_case['canonical_sha256'], $result['layout_descriptor_sha256'] );
	}

	/** Received root order does not change the Child's digest or returned order. */
	public function test_out_of_order_roots_use_child_digest() {
		$metadata                               = $this->metadata();
		$roots                                  = $metadata['layout_descriptor']['roots'];
		$metadata['layout_descriptor']['roots'] = array( $roots[2], $roots[0], $roots[1] );
		$this->assertSame( $this->metadata(), MainWP_Backup_Snapshots::validate_format_metadata( $metadata ) );
	}

	/** No allowed stable value contains a slash, so observe the actual encoder flags. */
	public function test_canonical_encoder_keeps_slashes_unescaped() {
		self::$encoding_flags = array();
		try {
			$result = MainWP_Backup_Snapshots::validate_format_metadata( $this->metadata() );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( array( JSON_UNESCAPED_SLASHES ), self::$encoding_flags );
			$slash_case = self::shared_descriptor_cases()['slash_core_version'][0];
			$this->assertStringNotContainsString( '\\/', \wp_json_encode( $slash_case['input_descriptor']['core_version'], self::$encoding_flags[0] ) );
		} finally {
			self::$encoding_flags = null;
		}
	}

	/** Unknown keys and non-string observations retain the corruption refusal. */
	public function test_descriptor_faults_remain_file_corrupt() {
		foreach ( array( array( 'core_version', 68 ), array( 'core_version', null ), array( 'unexpected', 'value' ) ) as $fault ) {
			$metadata                                   = $this->metadata();
			$metadata['layout_descriptor'][ $fault[0] ] = $fault[1];
			$result                                     = MainWP_Backup_Snapshots::validate_format_metadata( $metadata );
			$this->assertWPError( $result );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $result->get_error_code() );
		}
	}

	/** Child normalize requires integer 2 for both internal versions. */
	public function test_inner_versions_are_unsupported() {
		foreach ( array( array( 'format', 3 ), array( 'list_format', '2' ) ) as $fault ) {
			$metadata                                   = $this->metadata();
			$metadata['layout_descriptor'][ $fault[0] ] = $fault[1];
			$result                                     = MainWP_Backup_Snapshots::validate_format_metadata( $metadata );
			$this->assertWPError( $result );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		}
	}

	/** Each file artifact must carry the same integer version as its manifest. */
	public function test_string_file_format_is_refused_before_manifest_validation() {
		$archives = array(
			array(
				'role'                     => 'full',
				'backup_format'            => '2',
				'layout_descriptor_sha256' => $this->metadata()['layout_descriptor_sha256'],
			),
			$this->metadata() + array( 'role' => 'manifest' ),
		);
		$result   = MainWP_Backup_Snapshots::format_metadata( $archives );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
	}

	/** JSON storage preserves types; only legacy string 1 retains its old path. */
	public function test_outer_string_v2_is_unsupported_and_v1_unchanged() {
		$metadata                  = $this->metadata();
		$metadata['backup_format'] = '2';
		$result                    = MainWP_Backup_Snapshots::validate_format_metadata( $metadata );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$result = MainWP_Backup_Snapshots::format_metadata( array( $metadata + array( 'role' => 'manifest' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		foreach ( array( 1, '1' ) as $legacy ) {
			$this->assertSame( array(), MainWP_Backup_Snapshots::validate_format_metadata( array( 'backup_format' => $legacy ) ) );
			$this->assertSame( array(), MainWP_Backup_Snapshots::format_metadata( array( array( 'backup_format' => $legacy ) ) ) );
		}
	}
}
