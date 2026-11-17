<?php
/**
 * Remote MCP PHP fallback tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Fallback_Panel;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Requirements;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_PHP_Fallback
 */
class Test_MainWP_Remote_MCP_PHP_Fallback extends \WP_UnitTestCase {

	/**
	 * Files that must parse on PHP 7.4.
	 *
	 * @var array
	 */
	const FLOOR_FILES = array( 'bootstrap.php', 'class-remote-mcp-requirements.php', 'class-remote-mcp-fallback-panel.php', 'uninstall.php' );

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	/**
	 * Module directory.
	 *
	 * @return string
	 */
	private function module_dir(): string {
		return dirname( __DIR__, 2 ) . '/modules/remote-mcp';
	}

	/**
	 * Callbacks registered per hook.
	 *
	 * @return array Hook name => count.
	 */
	private function hooks(): array {
		global $wp_filter;
		$counts = array();
		foreach ( $wp_filter as $name => $hook ) {
			$count = 0;
			foreach ( $hook->callbacks as $callbacks ) {
				$count += count( $callbacks );
			}
			if ( $count ) {
				$counts[ $name ] = $count;
			}
		}
		return $counts;
	}

	/**
	 * Constructs PHP 7.4 can't parse, found by token.
	 *
	 * @param string $source PHP source.
	 * @return string[] One description per hit.
	 */
	private function php80_constructs( string $source ): array {
		$named = array( 'T_MATCH', 'T_ENUM', 'T_READONLY', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE' );
		$hits  = array();
		$all   = token_get_all( $source );
		$sig   = array_values(
			array_filter(
				$all,
				function ( $token ) {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		foreach ( $sig as $i => $token ) {
			if ( is_array( $token ) ) {
				foreach ( $named as $name ) {
					if ( defined( $name ) && constant( $name ) === $token[0] ) {
						$hits[] = $name . ' line ' . $token[2];
					}
				}
			}
			// Named argument: an identifier and a single colon right after "(" or ",".
			if ( is_array( $token ) && T_STRING === $token[0] && isset( $sig[ $i + 1 ], $sig[ $i - 1 ] ) && ':' === $sig[ $i + 1 ] && in_array( $sig[ $i - 1 ], array( '(', ',' ), true ) ) {
				$hits[] = 'named argument ' . $token[1];
			}
		}
		// Union types: a "|" inside a function's parameter list or return type, outside defaults.
		$count = count( $sig );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $sig[ $i ] ) || ! in_array( $sig[ $i ][0], array( T_FUNCTION, T_FN ), true ) ) {
				continue;
			}
			$depth   = 0;
			$default = false;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $sig[ $j ];
				if ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( 1 === $depth && '=' === $t ) {
					$default = true;
				} elseif ( 1 === $depth && ',' === $t ) {
					$default = false;
				} elseif ( 1 === $depth && '|' === $t && ! $default ) {
					$hits[] = 'union parameter type';
				}
			}
			if ( isset( $sig[ $j + 1 ] ) && ':' === $sig[ $j + 1 ] ) {
				for ( $k = $j + 2; $k < $count && ! in_array( $sig[ $k ], array( '{', ';' ), true ); $k++ ) {
					if ( '|' === $sig[ $k ] ) {
						$hits[] = 'union return type';
					}
				}
			}
		}
		return $hits;
	}

	public function test_below_minimum_php_registers_no_routes_or_rewrites() {
		global $wp_rewrite;
		$hooks  = $this->hooks();
		$rules  = get_option( 'rewrite_rules' );
		$extra  = $wp_rewrite->extra_rules_top;
		$cron   = _get_cron_array();
		$decide = '\\MainWP\\Dashboard\\Remote_MCP\\bootstrap_module';

		$this->assertSame( 'fallback', $decide( '7.4.33', false, $this->module_dir() ) );

		$after = $this->hooks();
		$added = array();
		foreach ( $after as $name => $count ) {
			if ( $count !== ( $hooks[ $name ] ?? 0 ) ) {
				$added[ $name ] = $count - ( $hooks[ $name ] ?? 0 );
			}
		}
		$this->assertSame( array( 'mainwp_mcp_direct_view' => 1 ), $added, 'only the panel hooks in' );
		$this->assertSame( $rules, get_option( 'rewrite_rules' ), 'no rewrite rules' );
		$this->assertSame( $extra, $wp_rewrite->extra_rules_top );
		$this->assertSame( $cron, _get_cron_array(), 'no cron events' );
	}

	public function test_below_minimum_panel_shows_required_and_running_versions() {
		$html = Remote_MCP_Fallback_Panel::render( '7.4.33' );
		$this->assertStringContainsString( 'Remote AI connections need PHP 8.1 or newer; this server runs 7.4.33.', $html );

		// The view it offers the MainWP MCP page renders the same notice for the running version, and
		// never becomes the default: the connector can't run here.
		Remote_MCP_Fallback_Panel::register_hooks();
		$view = apply_filters( 'mainwp_mcp_direct_view', null );
		remove_filter( 'mainwp_mcp_direct_view', array( Remote_MCP_Fallback_Panel::class, 'direct_view' ) );
		$this->assertIsArray( $view, 'the panel is offered' );
		$this->assertSame( array( Remote_MCP_Fallback_Panel::class, 'render_page' ), $view['render'] );
		$this->assertFalse( $view['preferred'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		call_user_func( $view['render'] );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'this server runs ' . PHP_VERSION, $out );
		$this->assertStringNotContainsString( 'mainwp_remote_mcp_action', $out, 'no panel actions below the floor' );
	}

	public function test_fallback_files_use_only_php74_tokens() {
		// The scanner must catch each construct, or a clean result proves nothing.
		$samples = array(
			'<?php $a = match ( $b ) { 1 => 2 };',
			'<?php enum Suit {}',
			'<?php class A { public readonly int $x; }',
			'<?php $a = $b?->c;',
			'<?php #[Attr] function f() {}',
			'<?php f( name: 1 );',
			'<?php function f( int|string $a ) {}',
			'<?php function f(): int|false {}',
		);
		foreach ( $samples as $sample ) {
			$this->assertNotEmpty( $this->php80_constructs( $sample ), $sample );
		}
		$this->assertSame( array(), $this->php80_constructs( '<?php $a = $b ? c : d; function f( $x = A | B, ?string $y = null ): ?array { return array( "k" => 1 ); }' ), 'ternaries, bitwise defaults and nullable types are fine' );

		foreach ( self::FLOOR_FILES as $file ) {
			$path = $this->module_dir() . '/' . $file;
			$this->assertFileExists( $path );
			$this->assertSame( array(), $this->php80_constructs( (string) file_get_contents( $path ) ), $file );
		}
	}

	public function test_activation_below_minimum_does_not_fatal() {
		$decide = '\\MainWP\\Dashboard\\Remote_MCP\\bootstrap_module';
		$this->assertSame( 'fallback', $decide( '7.4.33', false, $this->module_dir() ) );
		$this->assertSame( 'fallback', $decide( '8.0.30', false, $this->module_dir() ), 'loading twice is safe' );

		$result = Remote_MCP_Requirements::evaluate( '7.4.33', '7.0', false );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'php', $result['reason'] );

		// The 7.4 files may only name each other: below the floor the module autoloader isn't
		// registered, so any other module class would be a fatal "class not found".
		foreach ( array( 'class-remote-mcp-requirements.php', 'class-remote-mcp-fallback-panel.php' ) as $file ) {
			preg_match_all( '/\b(?:Remote_MCP|OAuth|MCP)_[A-Za-z_]+\b/', (string) file_get_contents( $this->module_dir() . '/' . $file ), $names );
			$this->assertSame( array(), array_values( array_diff( array_unique( $names[0] ), array( 'Remote_MCP_Requirements', 'Remote_MCP_Fallback_Panel' ) ) ), $file );
		}
	}
}
