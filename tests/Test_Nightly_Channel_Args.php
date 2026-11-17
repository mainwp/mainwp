<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit discovers test classes by filename.
/**
 * Nightly license requests and install-time package refresh without a database.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Exercise the production license client, result mapping and download callback. */
class Test_Nightly_Channel_Args extends TestCase {

	/**
	 * Run production classes with isolated WordPress services.
	 *
	 * @param array $input Scenario controls.
	 * @return array Observed results and requests.
	 */
	private function probe( array $input ): array {
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/nightly-channel-args.inc', json_encode( $input, JSON_THROW_ON_ERROR ) ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
			$pipes
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	/** Both protocols must send the filtered arguments to the license endpoint. */
	public function test_filter_arguments_reach_both_request_urls(): void {
		$result = $this->probe( array( 'mode' => 'args' ) );
		$types  = array( 'pluginupdatecheck', 'bulkupdatecheck' );
		$this->assertSame( $types, $result['filter_calls'] );
		$this->assertCount( 2, $result['requests'] );
		foreach ( $result['requests'] as $index => $url ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertSame( 'am-software-api', $query['mainwp-api'] );
			$this->assertSame( $types[ $index ], $query['request'] );
			$this->assertSame( 'nightly', $query['channel'] );
			$this->assertSame( 'dashboard.example.test', $query['object'] );
		}
	}

	/** A filter that leaves arguments alone preserves stable requests. */
	public function test_default_requests_have_no_channel(): void {
		$result = $this->probe(
			array(
				'mode'    => 'args',
				'nightly' => false,
			)
		);
		$this->assertCount( 2, $result['requests'] );
		foreach ( $result['requests'] as $url ) {
			parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertArrayNotHasKey( 'channel', $query );
		}
	}

	/** The update filter must leave all six license-operation URLs intact. */
	public function test_channel_does_not_change_license_base_urls(): void {
		$result = $this->probe( array( 'mode' => 'licenses' ) );
		$this->assertSame( array( 'pluginupdatecheck' ), $result['filter_calls'] );
		$this->assertCount( 6, $result['requests'] );
		foreach ( $result['requests'] as $url ) {
			$this->assertSame( 'https://mainwp.com/?mainwp-api=am-software-api', $url );
		}
	}

	/** Both response formats retain valid changelog data and trim item text. */
	public function test_changes_reach_both_result_objects(): void {
		$changes                        = array(
			array(
				'version' => '2.0-rc.2',
				'items'   => array(
					array(
						'type' => 'fixed',
						'text' => ' Keep the selected sort order. ',
					),
				),
			),
		);
		$result                         = $this->probe(
			array(
				'mode'    => 'changes',
				'changes' => $changes,
			)
		);
		$changes[0]['items'][0]['text'] = 'Keep the selected sort order.';
		foreach ( $result['result'] as $response ) {
			$this->assertSame( $changes, $response['changes'] );
			$this->assertSame( '2.0-rc.2', $response['new_version'] );
			$this->assertSame( 1, $response['apiManager'] );
		}
	}

	/**
	 * Malformed values must not become changelog data.
	 *
	 * @return array Invalid response fields.
	 */
	public static function malformed_changes(): array {
		return array(
			'absent'          => array( array() ),
			'scalar'          => array( array( 'changes' => 'unexpected' ) ),
			'null'            => array( array( 'changes' => null ) ),
			'missing version' => array( array( 'changes' => array( array( 'items' => array() ) ) ) ),
			'numeric version' => array(
				array(
					'changes' => array(
						array(
							'version' => 2,
							'items'   => array(),
						),
					),
				),
			),
			'missing items'   => array( array( 'changes' => array( array( 'version' => '2.0' ) ) ) ),
			'scalar items'    => array(
				array(
					'changes' => array(
						array(
							'version' => '2.0',
							'items'   => 'bad',
						),
					),
				),
			),
			'nested text'     => array(
				array(
					'changes' => array(
						array(
							'version' => '2.0',
							'items'   => array(
								array(
									'type' => 'fixed',
									'text' => array( 'bad' ),
								),
							),
						),
					),
				),
			),
			'unknown type'    => array(
				array(
					'changes' => array(
						array(
							'version' => '2.0',
							'items'   => array(
								array(
									'type' => 'script',
									'text' => 'bad',
								),
							),
						),
					),
				),
			),
			'HTML'            => array(
				array(
					'changes' => array(
						array(
							'version' => '2.0',
							'items'   => array(
								array(
									'type' => 'fixed',
									'text' => '<b>bad</b>',
								),
							),
						),
					),
				),
			),
			'object root'     => array( array( 'object_changes' => 'root' ) ),
			'object build'    => array( array( 'object_changes' => 'build' ) ),
			'object item'     => array( array( 'object_changes' => 'item' ) ),
		);
	}

	/**
	 * Ignore malformed changes while preserving an otherwise usable update.
	 *
	 * @dataProvider malformed_changes
	 * @param array $input Malformed field controls.
	 */
	public function test_malformed_changes_are_dropped( array $input ): void {
		$result = $this->probe( array_merge( array( 'mode' => 'changes' ), $input ) );
		// JSON objects become arrays in bulk responses; only serialization retains their class.
		$responses = isset( $input['object_changes'] ) ? array( $result['result']['single'] ) : $result['result'];
		foreach ( $responses as $response ) {
			$this->assertArrayNotHasKey( 'changes', $response );
			$this->assertSame( '2.0-rc.2', $response['new_version'] );
		}
	}

	/** Keep valid entries and items without carrying extra remote fields. */
	public function test_mixed_changes_keep_only_plain_fields(): void {
		$item   = array(
			'type' => 'added',
			'text' => 'New filter.',
		);
		$input  = array(
			array(
				'version' => '2.0',
				'items'   => array( $item + array( 'extra' => array( 'bad' ) ), array( 'text' => 'Missing type.' ) ),
				'date'    => '2026-11-03',
			),
			'bad',
		);
		$result = $this->probe(
			array(
				'mode'    => 'changes',
				'changes' => $input,
			)
		);
		foreach ( $result['result'] as $response ) {
			$this->assertSame(
				array(
					array(
						'version' => '2.0',
						'items'   => array( $item ),
					),
				),
				$response['changes']
			);
		}
	}

	/** Both protocols limit the response to seven builds and 100 items each. */
	public function test_changes_limit_builds_and_items(): void {
		$changes = array();
		for ( $build = 0; $build < 8; ++$build ) {
			$items = array();
			for ( $item = 0; $item < 101; ++$item ) {
				$items[] = array(
					'type' => 'fixed',
					'text' => 'Item ' . $item,
				);
			}
			$changes[] = array(
				'version' => '2.0-rc.' . $build,
				'items'   => $items,
			);
		}
		$result = $this->probe(
			array(
				'mode'    => 'changes',
				'changes' => $changes,
			)
		);
		foreach ( $result['result'] as $response ) {
			$this->assertCount( 7, $response['changes'] );
			$this->assertSame( '2.0-rc.6', $response['changes'][6]['version'] );
			foreach ( $response['changes'] as $change ) {
				$this->assertCount( 100, $change['items'] );
				$this->assertSame( 'Item 99', $change['items'][99]['text'] );
			}
		}
	}

	/** The 500-character limit counts Unicode characters after trimming. */
	public function test_changes_drop_overlong_text(): void {
		$text   = str_repeat( 'é', 500 );
		$items  = array(
			array(
				'type' => 'fixed',
				'text' => ' ' . $text . ' ',
			),
			array(
				'type' => 'fixed',
				'text' => $text . 'é',
			),
			array(
				'type' => 'fixed',
				'text' => str_repeat( 'a', 501 ),
			),
		);
		$result = $this->probe(
			array(
				'mode'    => 'changes',
				'changes' => array(
					array(
						'version' => '2.0',
						'items'   => $items,
					),
				),
			)
		);
		foreach ( $result['result'] as $response ) {
			$this->assertSame(
				array(
					array(
						'type' => 'fixed',
						'text' => $text,
					),
				),
				$response['changes'][0]['items']
			);
		}
	}

	/** Plain comparisons survive while tags and declarations are rejected. */
	public function test_changes_keep_plain_less_than_text_and_drop_markup(): void {
		$plain = array(
			'type' => 'fixed',
			'text' => 'sites<2',
		);
		$items = array( $plain );
		foreach ( array( '<b>Markup</b>', '</b>', '<!DOCTYPE html>', '<?php echo 1;', '<SCRIPT>bad</SCRIPT>' ) as $text ) {
			$items[] = array(
				'type' => 'fixed',
				'text' => $text,
			);
		}
		$result = $this->probe(
			array(
				'mode'    => 'changes',
				'changes' => array(
					array(
						'version' => '2.0',
						'items'   => $items,
					),
				),
			)
		);
		foreach ( $result['result'] as $response ) {
			$this->assertSame( array( $plain ), $response['changes'][0]['items'] );
		}
	}

	/** The returned temp file must come from the fresh, filtered nightly URL. */
	public function test_refresh_downloads_the_fresh_nightly_package(): void {
		$result = $this->probe( array( 'mode' => 'refresh' ) );
		$this->assertSame( '/tmp/mainwp-nightly-fixture.zip', $result['result'] );
		$this->assertSame( array( 'https://mainwp.com/wp-json/mainwp-nightly/v1/addon-download?product=fixture&sig=fresh&filtered=1' ), $result['downloads'] );
		$this->assertSame( array( 'pluginupdatecheck' ), $result['filter_calls'] );
		$this->assertCount( 1, $result['requests'] );
		parse_str( parse_url( $result['requests'][0], PHP_URL_QUERY ), $query );
		$this->assertSame( 'nightly', $query['channel'] );
		$this->assertSame( 'fixture-addon', $query['plugin_name'] );
		$this->assertCount( 1, $result['package_filters'] );
		$this->assertSame( 'skin-original', $result['skin_package'] );
	}

	/** An add-on's API name can differ from its installed directory. */
	public function test_refresh_uses_registered_api_name(): void {
		$result = $this->probe(
			array(
				'mode' => 'refresh',
				'api'  => 'fixture-api-alias',
			)
		);
		$this->assertSame( '/tmp/mainwp-nightly-fixture.zip', $result['result'] );
		$this->assertCount( 1, $result['requests'] );
		$this->assertCount( 1, $result['downloads'] );
		parse_str( parse_url( $result['requests'][0], PHP_URL_QUERY ), $query );
		$this->assertSame( 'fixture-api-alias', $query['plugin_name'] );
		$this->assertSame( 'nightly', $query['channel'] );
	}

	/**
	 * Other packages and prior results must pass through without requests.
	 *
	 * @return array Inputs and expected replies.
	 */
	public static function untouched_packages(): array {
		return array(
			'other plugin'               => array( array( 'plugin' => 'other/other.php' ), false ),
			'same directory, other file' => array( array( 'plugin' => 'fixture-addon/other.php' ), false ),
			'no plugin'                  => array( array( 'plugin' => null ), false ),
			'earlier temp file'          => array( array( 'reply' => '/tmp/earlier.zip' ), '/tmp/earlier.zip' ),
			'earlier error'              => array( array( 'reply_error' => true ), array( 'code' => 'earlier_filter' ) ),
			'stable rollback install'    => array(
				array(
					'hook_extra' => array(
						'type'   => 'plugin',
						'action' => 'install',
					),
					'package'    => 'https://mainwp.com/?download_file=123&order=456&key=fixture-key',
				),
				false,
			),
		);
	}

	/**
	 * A stable rollback must never trigger a nightly refresh while opted in.
	 *
	 * @dataProvider untouched_packages
	 * @param array $input Scenario controls.
	 * @param mixed $expected Original filter reply.
	 */
	public function test_unrelated_packages_and_prior_replies_pass_through( array $input, $expected ): void {
		$result = $this->probe( array_merge( array( 'mode' => 'refresh' ), $input ) );
		$this->assertSame( $expected, $result['result'] );
		$this->assertSame( array(), $result['requests'] );
		$this->assertSame( array(), $result['filter_calls'] );
		$this->assertSame( array(), $result['downloads'] );
	}

	/** A failed refresh must not fall back to the original expired URL. */
	public function test_refresh_failures_return_errors(): void {
		foreach ( array( array( 'http_code' => 503 ), array( 'response_package' => '' ) ) as $input ) {
			$result = $this->probe( array_merge( array( 'mode' => 'refresh' ), $input ) );
			$this->assertSame( array( 'code' => 'mainwp_extension_package_unavailable' ), $result['result'] );
			$this->assertSame( array(), $result['downloads'] );
		}
		$result = $this->probe(
			array(
				'mode'           => 'refresh',
				'download_error' => true,
			)
		);
		$this->assertSame( array( 'code' => 'fixture_download_failed' ), $result['result'] );
		$this->assertCount( 1, $result['downloads'] );
	}

	/** License errors retain bounded plain detail without attempting a download. */
	public function test_refresh_license_errors_have_distinct_code_and_detail(): void {
		$cases = array(
			array( "<b>Expired</b> license.\n Check the key.", 'Expired license. Check the key.' ),
			array( str_repeat( 'é', 201 ), str_repeat( 'é', 200 ) ),
			array( array( 'license' => 'expired' ), '{"license":"expired"}' ),
		);
		foreach ( $cases as list( $error, $expected ) ) {
			$result = $this->probe(
				array(
					'mode'      => 'refresh',
					'api_error' => $error,
				)
			);
			$this->assertSame( array( 'code' => 'mainwp_extension_license_error' ), $result['result'] );
			$this->assertSame( 'The license check for this add-on failed. Check its license on the Extensions page.', $result['error_message'] );
			$this->assertSame( $expected, $result['error_data'] );
			$this->assertSame( array(), $result['downloads'] );
		}
	}

	/** Packaging must exclude the review configuration. */
	public function test_coderabbit_configuration_is_export_ignored(): void {
		$this->assertStringContainsString( ".coderabbit.yaml export-ignore\n", file_get_contents( dirname( __DIR__ ) . '/.gitattributes' ) );
	}
}
