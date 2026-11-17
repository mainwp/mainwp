<?php
/**
 * Restore upload pacing through the real cURL polling loop.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- Standalone subprocess has no WordPress bootstrap.

/** Tests transport readiness without wall-clock assertions or network access. */
class Test_Restore_Chunk_Transport_Pacing extends \PHPUnit\Framework\TestCase {

	/**
	 * Only ready restore uploads bypass the extra polling sleep.
	 *
	 * @dataProvider pacing_cases
	 * @param string|null $action Request action, or absent.
	 * @param int         $ready Select result.
	 * @param array       $waits Expected waits in microseconds.
	 */
	public function test_polling_waits( $action, $ready, $waits ): void {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/restore-chunk-pacing.inc' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite(
			$pipes[0],
			json_encode(
				array(
					'action' => $action,
					'ready'  => $ready,
				)
			)
		);
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $err );
		$this->assertSame( $waits, json_decode( $out, true ), $out . $err );
	}

	/**
	 * Transport readiness and action combinations.
	 *
	 * @return array
	 */
	public function pacing_cases(): array {
		return array(
			'ready restore' => array( 'restore_artifact_chunk', 1, array() ),
			'idle restore'  => array( 'restore_artifact_chunk', 0, array( 10000 ) ),
			'no socket'     => array( 'restore_artifact_chunk', -1, array( 100000, 10000 ) ),
			'other action'  => array( 'stats', 1, array( 10000 ) ),
			'no action'     => array( null, 1, array( 10000 ) ),
		);
	}
}
