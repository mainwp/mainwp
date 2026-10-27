<?php
/**
 * Run the global permission check with real WordPress hooks in a fresh process.
 *
 * @package MainWP\Dashboard\Tests
 */

$context_constant = $argv[2] ?? '';
if ( ! in_array( $context_constant, array( 'DOING_CRON', 'WP_CLI', 'MAINWP_REST_API_DOING' ), true ) ) {
	throw new InvalidArgumentException( 'Unknown permission context.' );
}

define( 'ABSPATH', $argv[1] );
require_once ABSPATH . 'wp-includes/plugin.php';
require_once dirname( __DIR__, 2 ) . '/class/class-mainwp-system.php';

$system = ( new ReflectionClass( \MainWP\Dashboard\MainWP_System::class ) )->newInstanceWithoutConstructor();
$system->plugins_loaded();

// A populated user keeps the ordinary request control from loading pluggable.php.
$GLOBALS['current_user'] = (object) array( 'ID' => 1 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The standalone fixture has no WordPress user session.
$calls                   = array();
add_filter(
	'mainwp_currentusercan',
	static function ( $initial_grant, $type, $right ) use ( &$calls ): bool {
		$calls[] = array( $initial_grant, $type, $right );
		return false;
	},
	10,
	3
);

if ( false !== \MainWP\Dashboard\mainwp_current_user_have_right( 'site', 42 ) || array( array( true, 'site', 42 ) ) !== $calls ) {
	throw new RuntimeException( 'The ordinary request must reach the denying permission filter.' );
}

$calls = array();
define( $context_constant, true );
$permission = \MainWP\Dashboard\mainwp_current_user_have_right( 'site', 42 );
echo json_encode(
	array(
		'permission' => $permission,
		'calls'      => $calls,
	),
	JSON_THROW_ON_ERROR
);
