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
 // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- The encoder observer belongs to this fixture.
	/**
	 * Observed flags; disabled outside the encoder test.
	 *
	 * @var array|null
	 */
	public static $encoding_flags = null;

	const STABLE   = '{"format":2,"roots":[{"role":"site","coverage":"preserved"},{"role":"uploads","coverage":"restore"},{"role":"config","coverage":"recovery_only"}],"layout_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","core_policy":"managed_preserve","list_format":2,"path_encoding":"role-prefix"}';
	const OBSERVED = '{"format":2,"roots":[{"role":"site","coverage":"preserved"},{"role":"uploads","coverage":"restore"},{"role":"config","coverage":"recovery_only"}],"layout_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","core_policy":"managed_preserve","list_format":2,"path_encoding":"role-prefix","core_version":"6.8/beta-2"}';
	const DIGEST   = 'd14bdfcecd100bef90eb4bf74caa8476f17848a5331f687c410f1192008c4b86';

	/**
	 * Build an envelope around the Child bytes.
	 *
	 * @param string $bytes Child metadata JSON.
	 * @return array
	 */
	private function metadata( $bytes = self::STABLE ) {
		return array(
			'backup_format'            => 2,
			'layout_descriptor'        => json_decode( $bytes, true ),
			'layout_descriptor_sha256' => self::DIGEST,
		);
	}

	/** Literal archive metadata retains the observed version outside the digest. */
	public function test_child_metadata_bytes_and_observation() {
		foreach ( array( self::STABLE, self::OBSERVED ) as $bytes ) {
			$metadata = $this->metadata( $bytes );
			$result   = MainWP_Backup_Snapshots::validate_format_metadata( $metadata );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( $metadata, $result );
			$stable = $result['layout_descriptor'];
			unset( $stable['core_version'] );
			$this->assertSame( self::STABLE, \wp_json_encode( $stable, JSON_UNESCAPED_SLASHES ) );
			$this->assertSame( self::DIGEST, hash( 'sha256', \wp_json_encode( $stable, JSON_UNESCAPED_SLASHES ) ) );
		}
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
			$this->assertSame( '"6.8/beta-2"', \wp_json_encode( '6.8/beta-2', self::$encoding_flags[0] ) );
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
				'layout_descriptor_sha256' => self::DIGEST,
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
