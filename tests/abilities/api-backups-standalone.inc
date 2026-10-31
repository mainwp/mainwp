<?php
/**
 * Isolated WordPress doubles for missing-helper and mutation checks.
 *
 * Run from the repository root:
 * php tests/abilities/api-backups-standalone.inc
 * php tests/abilities/api-backups-standalone.inc no-api
 * php tests/abilities/api-backups-standalone.inc no-helper
 * php tests/abilities/api-backups-standalone.inc no-extension-helper
 * php tests/abilities/api-backups-standalone.inc module-disabled
 *
 * To test a scratch mutation, set API_BACKUPS_ABILITY_FILE to its absolute
 * filename before the same command. Mutations must stay outside the repository.
 *
 * @package MainWP\Dashboard\Tests
 */

// These isolated doubles preserve public signatures and replace globals without loading WordPress.
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound,Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden,Universal.Files.SeparateFunctionsFromOO.Mixed,WordPress.WP.GlobalVariablesOverride.Prohibited

namespace MainWP\Dashboard {
	class MainWP_DB {
		public static function instance() {
			return new self(); }
		public function get_table_name( $suffix ) {
			return 'wp_mainwp_' . $suffix; }
	}
	if ( 'no-helper' !== ( $argv[1] ?? '' ) ) {
		class MainWP_Abilities_Util {
			public static function check_rest_api_permission( $input = null ) {
				return $GLOBALS['dashboard_permission']; }
			public static function current_user_has_operation_right( $right ) {
				return $GLOBALS['extension_permission']; }
			public static function get_scoped_site_ids_or_all( $ownership = true ) {
				return $GLOBALS['scoped_sites']; }
			public static function get_scoped_group_ids_or_all( $ownership = true ) {
				return $GLOBALS['scoped_groups']; }
			public static function check_site_access( $id, $input = null ) {
				++$GLOBALS['site_checks'];
				return ! in_array( $id, $GLOBALS['dashboard_hidden'], true ); }
		}
	}
}

namespace {
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['module_enabled']       = 'module-disabled' !== ( $argv[1] ?? '' );
	$GLOBALS['scoped_sites']         = 'all';
	$GLOBALS['scoped_groups']        = 'all';
	$GLOBALS['dashboard_permission'] = true;
	$GLOBALS['admin']                = true;
	$GLOBALS['extension_permission'] = true;
	$GLOBALS['extension_hidden']     = array();
	$GLOBALS['dashboard_hidden']     = array();
	$GLOBALS['site_checks']          = 0;
	$GLOBALS['registered']           = array();
	$GLOBALS['actions']              = array();
	$GLOBALS['checks']               = 0;
	$GLOBALS['side_effects']         = array();
	$GLOBALS['raw_option_reads']     = 0;
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code, $message, $data ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data; }
		public function get_error_code() {
			return $this->code; }
	}
	function __( $value, $domain = '' ) {
		return $value; }
	function is_wp_error( $value ) {
		return $value instanceof WP_Error; }
	function current_user_can( $capability ) {
		return $GLOBALS['admin']; }
	function mainwp_modules_is_enabled( $name ) {
		return $GLOBALS['module_enabled']; }
	function wp_strip_all_tags( $value ) {
		return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value ) ) ); }
	function get_gmt_from_date( $value, $format ) {
		return ( new DateTimeImmutable( $value, new DateTimeZone( 'America/New_York' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format ); }
	function add_action( $hook, $callback ) {
		$GLOBALS['actions'][ $hook ][] = $callback; }
	if ( 'no-api' !== ( $argv[1] ?? '' ) ) {
		function wp_register_ability( $name, $definition ) {
			$GLOBALS['registered'][ $name ] = $definition; }
		function wp_register_ability_category( $name, $definition ) {
			$GLOBALS['category'] = $name; }
	}
	if ( 'no-extension-helper' !== ( $argv[1] ?? '' ) ) {
		function mainwp_current_user_can( $kind, $id ) {
			return 'dashboard' === $kind ? $GLOBALS['extension_permission'] && 'manage_api_backups' === $id : ! in_array( $id, $GLOBALS['extension_hidden'], true ); }
	}
	function forbidden( $name ) {
		$GLOBALS['side_effects'][] = $name;
		throw new RuntimeException( 'Unexpected side effect: ' . $name ); }
	function update_option( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function update_post_meta( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function wp_remote_get( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function wp_remote_post( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function wp_remote_request( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function wp_mail( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function wp_schedule_event( ...$args ) {
		forbidden( __FUNCTION__ ); }
	function get_option( $name ) {
		++$GLOBALS['raw_option_reads'];
		return $GLOBALS['wpdb']->options_data[ $name ] ?? false; }
	function apply_filters( ...$args ) {
		forbidden( 'unapproved filter or decryption' ); }
	class Fake_Wpdb {
		public $options      = 'wp_options';
		public $last_error   = '';
		public $queries      = array();
		public $suppressed   = false;
		public $fail         = false;
		public $options_data = array();
		public $sites        = array();
		public function suppress_errors( $value ) {
			$previous         = $this->suppressed;
			$this->suppressed = $value;
			return $previous; }
		public function prepare( $sql, ...$args ) {
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0]; }
			check( preg_match_all( '/%[isd]/', $sql ) === count( $args ), 'Prepared placeholder and argument counts match' );
			return preg_replace_callback(
				'/%[isd]/',
				static function ( $placeholder ) use ( &$args ) {
					$value = array_shift( $args );
					if ( '%i' === $placeholder[0] ) {
						return '`' . $value . '`'; }
					return '%d' === $placeholder[0] ? (string) (int) $value : "'" . str_replace( "'", "''", $value ) . "'";
				},
				$sql
			);
		}
		public function get_results( $sql, $format ) {
			$this->queries[]  = $sql;
			$this->last_error = '';
			if ( $this->fail ) {
				$this->last_error = 'FAKEKEY-SENTINEL-1';
				return null; }
			if ( str_starts_with( $sql, 'SELECT option_name, CASE' ) ) {
				check( ! str_contains( $sql, 'AS value' ), 'Credentials are not projected as values' );
				$out = array();
				foreach ( $this->options_data as $name => $value ) {
					if ( str_contains( $sql, "'" . $name . "'" ) ) {
						$out[] = array(
							'option_name' => $name,
							'present'     => ! in_array( $value, array( '', 'a:0:{}', 'b:0;', 'N;' ), true ),
							'enabled'     => '1' === $value,
						);
					}
				}
				return $out;
			}
			if ( str_starts_with( $sql, 'SELECT wp.id, CASE' ) ) {
				preg_match_all( "/WHEN '([^']+)' THEN '([^']+)'/", $sql, $cases );
				$providers = array_combine( $cases[1], $cases[2] );
				preg_match( '/credential.name IN \\(([^)]+)\\)/', $sql, $credential_list );
				preg_match_all( "/'([^']+)'/", $credential_list[1], $credential_names );
				preg_match( '/credential.value NOT IN \\(([^)]+)\\)/', $sql, $empty_list );
				preg_match_all( "/'([^']*)'/", $empty_list[1], $empty_values );
				$out = array();
				foreach ( $this->sites as $id => $site ) {
					$provider = is_string( $site['provider'] ) ? strtolower( $site['provider'] ) : '';
					$out[]    = array(
						'id'                    => $id,
						'individual_configured' => isset( $site['individual'] ) && ! in_array( (string) $site['individual'], $empty_values[1], true ) && in_array( 'mainwp_api_backups_' . $provider . '_api_key', $credential_names[1], true ),
						'provider'              => $providers[ $provider ] ?? null,
					);
				}
				return $out;
			}
			if ( str_starts_with( $sql, 'SELECT wp.id, wp.name, backup.value AS last_backup' ) ) {
				preg_match( '/LOWER\\(provider.value\\) IN \\(([^)]+)\\)/', $sql, $provider_list );
				preg_match_all( "/'([^']+)'/", $provider_list[1], $provider_names );
				preg_match( '/WHERE wp.id IN \(([0-9,]+)\)/', $sql, $placeholder );
				$out = array();
				foreach ( explode( ',', $placeholder[1] ) as $id ) {
					$site  = $this->sites[ (int) $id ];
					$out[] = array(
						'id'          => $id,
						'name'        => $site['name'],
						'last_backup' => in_array( strtolower( $site['provider'] ?? '' ), $provider_names[1], true ) ? $site['last_backup'] : null,
					); }
				return $out;
			}
			throw new RuntimeException( 'Unexpected query projection' );
		}
	}
	function check( $condition, $label ) {
		++$GLOBALS['checks'];
		if ( ! $condition ) {
			throw new RuntimeException( $label ); } }
	function exact_keys( $row, $keys, $label ) {
		$actual = array_keys( $row );
		sort( $actual );
		sort( $keys );
		check( $actual === $keys, $label ); }
	$sentinels = array( 'FAKEKEY-SENTINEL-1', 'Bearer fake.jwt.token', 'password=FAKEPASS', 'https://zqx-sentinel.test/?token=FAKE', '/fake/private/key-file.pem' );
	function no_secrets( $value, $label ) {
		global $sentinels;
		$json = json_encode( $value );
		foreach ( $sentinels as $index => $sentinel ) {
			$last_offset = strlen( $sentinel ) - 4;
			for ( $i = 0; $i <= $last_offset; ++$i ) {
				check( ! str_contains( $json, substr( $sentinel, $i, 4 ) ), $label . ': secret fragment fixture ' . $index . ' offset ' . $i ); }
		}
	}
	$ability_file = getenv( 'API_BACKUPS_ABILITY_FILE' ) ?: dirname( __DIR__, 2 ) . '/includes/abilities/class-mainwp-abilities-api-backups.php';
	check( is_file( $ability_file ), 'Read abilities class must exist' );
	require $ability_file;
	$class = '\\MainWP\\Dashboard\\MainWP_Abilities_Api_Backups';
	$class::register();
	if ( 'module-disabled' === ( $argv[1] ?? '' ) ) {
		check( array() === $GLOBALS['registered'], 'Disabled module has no abilities' );
		check( 'mainwp_module_disabled' === $class::execute_site_status()->code, 'Disabled module denies execution' );
		echo "PASS module-disabled\n";
		exit; }
	if ( 'no-api' === ( $argv[1] ?? '' ) ) {
		check( array() === $GLOBALS['actions'], 'No hooks without API' );
		echo "PASS no-api\n";
		exit; }
	if ( in_array( $argv[1] ?? '', array( 'no-helper', 'no-extension-helper' ), true ) ) {
		foreach ( array( 'check_permission', 'check_providers_permission', 'execute_providers', 'execute_site_status' ) as $method ) {
			$denied = $class::$method();
			check( is_wp_error( $denied ) && 'mainwp_permission_denied' === $denied->code, 'Missing helper denial: ' . $method ); }
		echo 'PASS ' . $argv[1] . "\n";
		exit;
	}
	check( 2 === count( $GLOBALS['registered'] ), 'Both abilities registered' );
	$page_schema = $GLOBALS['registered']['mainwp/list-api-backups-site-status-v1']['input_schema']['properties']['page'];
	check( isset( $page_schema['anyOf'] ) && ! isset( $page_schema['oneOf'] ), 'Page schema accepts overlapping core integer and string matches' );
	foreach ( array( 'mainwp/list-api-backups-providers-v1', 'mainwp/list-api-backups-site-status-v1' ) as $name ) {
		$definition = $GLOBALS['registered'][ $name ];
		check( isset( $definition['input_schema'], $definition['output_schema'] ), $name . ' schemas' );
		check( is_callable( $definition['permission_callback'] ) && is_callable( $definition['execute_callback'] ), $name . ' callbacks' );
		check(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			) === $definition['meta']['annotations'],
			$name . ' annotations'
		);
		check( false === $definition['output_schema']['additionalProperties'], $name . ' exact output schema' );
	}
	$GLOBALS['wpdb'] = new Fake_Wpdb();
	$wpdb            = $GLOBALS['wpdb'];
	$providers       = array( 'cloudways', 'gridpane', 'vultr', 'linode', 'digitalocean', 'cpanel', 'plesk', 'kinsta' );
	$native_source   = file_get_contents( dirname( __DIR__, 2 ) . '/modules/api-backups/classes/class-api-backups-utility.php' );
	foreach ( array( 'get_child_api_key', 'update_child_api_key' ) as $method ) {
		check( 1 === preg_match( '/public function ' . $method . '\\([^)]*\\)\\s*\\{\\s*\\$names\\s*=\\s*array\\((.*?)\\);/s', $native_source, $matches ), 'Native storage provider array is located' );
		preg_match_all( "/'([^']+)'/", $matches[1], $native_names );
		$expected = $native_names[1];
		$actual   = $providers;
		sort( $expected );
		sort( $actual );
		check( $expected === $actual, 'Pinned providers match native ' . $method . ' support' );
	}
	foreach ( $providers as $i => $slug ) {
		$wpdb->options_data[ 'mainwp_api_backups_' . $slug . '_api_key' ] = serialize(
			array(
				'encrypted_val' => $sentinels[ $i % count( $sentinels ) ],
				'file_key'      => $sentinels[4],
			)
		);
		$wpdb->options_data[ 'mainwp_enable_' . $slug . '_api' ]          = $i % 2 ? '0' : '1';
		$wpdb->sites[ $i + 1 ] = array(
			'name'           => '<b>Readable ' . ( $i + 1 ) . '</b>',
			'provider'       => $slug,
			'last_backup'    => '1761782400',
			'secret_options' => $sentinels,
		);
	}
	$wpdb->sites[9] = array(
		'name'           => 'Never',
		'provider'       => null,
		'last_backup'    => null,
		'secret_options' => $sentinels,
	);
	$wpdb->options_data['mainwp_cloudways_api_account_email'] = 'fake-private@fake.invalid';
	$before  = serialize( array( $wpdb->options_data, $wpdb->sites ) );
	$log     = tempnam( sys_get_temp_dir(), 'kevin-jayBE-test-log-' );
	$old_log = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Capture only this isolated process's diagnostics to verify read purity.
	set_exception_handler(
		static function ( $error ) use ( $old_log ) {
			while ( ob_get_level() ) {
				ob_end_clean();
			} ini_set( 'error_log', $old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore this isolated process's original diagnostic destination.
			fwrite( STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL );
			exit( 255 );
		}
	);
	ob_start();
	$list = $class::execute_providers();
	exact_keys( $list, array( 'providers' ), 'Provider wrapper keys' );
	check( 8 === count( $list['providers'] ), 'All configured providers, including disabled' );
	foreach ( $list['providers'] as $i => $row ) {
		exact_keys( $row, array( 'provider', 'name', 'connected', 'site_count' ), 'Provider row keys' );
		check( $row['provider'] === $providers[ $i ] && ( 0 === $i % 2 ) === $row['connected'] && 1 === $row['site_count'], 'Provider indicators and accessible count' );
	}
	no_secrets( $list, 'Provider success' );
	$config_before = $wpdb->options_data;
	unset( $wpdb->options_data['mainwp_api_backups_cloudways_api_key'] );
	$wpdb->options_data['mainwp_api_backups_cloudways_access_token'] = serialize(
		array(
			'encrypted_val' => $sentinels[1],
			'file_key'      => $sentinels[4],
		)
	);
	foreach ( array(
		6 => 'cpanel',
		7 => 'plesk',
		8 => 'kinsta',
	) as $id => $slug ) {
		unset( $wpdb->options_data[ 'mainwp_api_backups_' . $slug . '_api_key' ] );
		$wpdb->sites[ $id ]['individual'] = true; }
	$alternate = $class::execute_providers();
	check( 8 === count( $alternate['providers'] ), 'Cloudways token and individual credentials count as configured' );
	no_secrets( $alternate, 'Alternate credentials' );
	$GLOBALS['dashboard_hidden'] = array( 6 );
	check( 7 === count( $class::execute_providers()['providers'] ), 'Hidden individual credentials do not configure a visible provider' );
	$GLOBALS['dashboard_hidden'] = array();
	foreach ( array( 6, 7, 8 ) as $id ) {
		unset( $wpdb->sites[ $id ]['individual'] ); }
	$wpdb->options_data = $config_before;
	foreach ( $providers as $index => $slug ) {
		unset( $wpdb->options_data[ 'mainwp_api_backups_' . $slug . '_api_key' ] );
		$wpdb->sites[ $index + 1 ]['individual'] = true;
	}
	unset( $wpdb->options_data['mainwp_api_backups_cloudways_access_token'] );
	$individual_only = $class::execute_providers();
	check( array_column( $individual_only['providers'], 'provider' ) === $providers, 'All eight individual-only stored providers are listed' );
	foreach ( $individual_only['providers'] as $index => $row ) {
		check( 1 === $row['site_count'] && ( 0 === $index % 2 ) === $row['connected'], 'Individual-only counts and enabled indicators' );
	}
	no_secrets( $individual_only, 'All individual credentials' );
	foreach ( array( 'a:0:{}', 'b:0;', 'N;' ) as $empty_value ) {
		foreach ( array_keys( $providers ) as $index ) {
			$wpdb->sites[ $index + 1 ]['individual'] = $empty_value;
		}
		$empty_credentials = $class::execute_providers();
		check( array() === $empty_credentials['providers'], 'Serialized empty individual credentials do not configure providers: ' . $empty_value );
		$assigned_sites = $class::execute_site_status();
		check( array_column( array_slice( $assigned_sites['sites'], 0, 8 ), 'provider' ) === $providers, 'Serialized empty credentials preserve provider assignments' );
		no_secrets( $empty_credentials, 'Serialized empty credential providers' );
		no_secrets( $assigned_sites, 'Serialized empty credential sites' );
	}
	foreach ( array_keys( $providers ) as $index ) {
		$wpdb->sites[ $index + 1 ]['individual'] = true;
	}
	$sites_before_unknown = $wpdb->sites;
	foreach ( $providers as $index => $slug ) {
		$wpdb->sites[ $index + 1 ]['provider'] = strtoupper( $slug );
	}
	$wpdb->sites[9]['provider']    = $sentinels[0];
	$wpdb->sites[9]['last_backup'] = '1761782400';
	$wpdb->sites[9]['individual']  = true;
	$unknown_sites                 = $class::execute_site_status();
	check( array_column( array_slice( $unknown_sites['sites'], 0, 8 ), 'provider' ) === $providers, 'Mixed-case provider storage is normalized' );
	check( null === $unknown_sites['sites'][8]['provider'] && null === $unknown_sites['sites'][8]['last_backup'], 'Unknown stored provider and timestamp remain null' );
	check( array_column( $class::execute_providers()['providers'], 'provider' ) === $providers, 'Unknown individual credential cannot add a provider' );
	no_secrets( $unknown_sites, 'Unknown stored provider' );
	$wpdb->sites = $sites_before_unknown;
	foreach ( array_keys( $providers ) as $index ) {
		unset( $wpdb->sites[ $index + 1 ]['individual'] );
	}
	$wpdb->options_data = $config_before;
	foreach ( array( 'scoped_sites', 'scoped_groups' ) as $scope ) {
		$GLOBALS[ $scope ] = array( 1 );
		$denied            = $class::execute_providers();
		check( is_wp_error( $denied ) && 'mainwp_restricted_account' === $denied->code, 'Global read refuses restricted ' . $scope );
		no_secrets( $denied, 'Restricted settings error' );
		$GLOBALS[ $scope ] = 'all'; }

	$sites = $class::execute_site_status();
	exact_keys( $sites, array( 'sites', 'page', 'per_page', 'total', 'hidden_site_count' ), 'Site wrapper keys' );
	check( 9 === $sites['total'] && 0 === $sites['hidden_site_count'] && 20 === $sites['per_page'], 'Default totals' );
	foreach ( $sites['sites'] as $row ) {
		exact_keys( $row, array( 'site_id', 'name', 'provider', 'last_backup', 'last_status' ), 'Site row keys' );
		check( 'unknown' === $row['last_status'], 'No fabricated completion status' ); }
	check( 'Readable 1' === $sites['sites'][0]['name'], 'Child HTML stripped' );
	check( '2025-10-30T04:00:00+00:00' === $sites['sites'][0]['last_backup'], 'Legacy local request timestamp converted to UTC' );
	check( null === $sites['sites'][8]['last_backup'] && null === $sites['sites'][8]['provider'], 'Never defaults' );
	no_secrets( $sites, 'Site success' );
	foreach ( array( PHP_INT_MAX, str_repeat( '9', 100 ) ) as $page ) {
		$huge = $class::execute_site_status(
			array(
				'page'     => $page,
				'per_page' => 100,
			)
		);
		check( ! is_wp_error( $huge ) && array() === $huge['sites'] && 9 === $huge['total'], 'Huge page is empty with normal total' );
		check( intdiv( PHP_INT_MAX, 100 ) === $huge['page'], 'Huge page is clamped before offset multiplication' );
		no_secrets( $huge, 'Huge page' );
	}
	check( 100 === $class::execute_site_status( array( 'per_page' => 1000 ) )['per_page'], 'Page size capped' );
	check( 1 === $class::execute_site_status( array( 'per_page' => 0 ) )['per_page'], 'Page size floor' );
	foreach ( array( 2, '2' ) as $page ) {
		check(
			3 === $class::execute_site_status(
				array(
					'per_page' => 2,
					'page'     => $page,
				)
			)['sites'][0]['site_id'],
			'Second page with integer or numeric string'
		); }
	foreach ( $providers as $provider ) {
		$filtered = $class::execute_site_status( array( 'provider' => $provider ) );
		check( 1 === $filtered['total'], 'Provider filter' );
		no_secrets( $filtered, 'Filtered' ); }
		$wpdb->queries = array();
	$class::execute_site_status();
	$query_count = count( $wpdb->queries );
	$saved_site  = $wpdb->sites[1];
	for ( $id = 10; $id <= 109; ++$id ) {
		$wpdb->sites[ $id ] = $saved_site; }
	$wpdb->queries = array();
	$class::execute_site_status( array( 'per_page' => 100 ) );
	check( count( $wpdb->queries ) === $query_count && 2 === $query_count, 'Read query count stays two as sites grow' );
	for ( $id = 10; $id <= 109; ++$id ) {
		unset( $wpdb->sites[ $id ] ); }
	$GLOBALS['extension_hidden'] = array( 2 );
	$GLOBALS['dashboard_hidden'] = array( 3 );
	$hidden                      = $class::execute_site_status();
	check( 7 === $hidden['total'] && 2 === $hidden['hidden_site_count'], 'Both independent site gates applied' );
	check( array( 1, 4, 5, 6, 7, 8, 9 ) === array_column( $hidden['sites'], 'site_id' ), 'Inaccessible sites absent' );
	check( 0 === $class::execute_providers()['providers'][1]['site_count'], 'Provider counts scoped' );
	no_secrets( $hidden, 'Scoped output' );
	$GLOBALS['extension_hidden'] = array();
	$GLOBALS['dashboard_hidden'] = array();
	foreach ( array( 'admin', 'extension_permission', 'dashboard_permission' ) as $gate ) {
		$GLOBALS[ $gate ] = false;
		foreach ( array( 'execute_providers', 'execute_site_status' ) as $method ) {
			$denied = $class::$method();
			check( is_wp_error( $denied ) && 'mainwp_permission_denied' === $denied->code, 'Direct execute denied: ' . $gate );
			no_secrets( $denied, 'Permission error' ); }
		$GLOBALS[ $gate ] = true;
	}
	foreach ( array( array( 'provider' => $sentinels[0] ), array( 'page' => $sentinels[1] ), array( 'page' => 0 ), array( 'page' => array() ), false ) as $input ) {
		$error = $class::execute_site_status( $input );
		check( is_wp_error( $error ) && 400 === $error->data['status'], 'Invalid input safely refused' );
		no_secrets( $error, 'Invalid error' ); }
	$wpdb->fail = true;
	$error      = $class::execute_site_status();
	check( is_wp_error( $error ) && 500 === $error->data['status'], 'Read failure is explicit' );
	no_secrets( $error, 'Read error' );
	check( false === $wpdb->suppressed, 'Database error setting restored' );
	$wpdb->fail = false;
	foreach ( $sentinels as $sentinel ) {
		$wpdb->sites[1]['name'] = $sentinel;
		$out                    = $class::execute_site_status();
		check( '' === $out['sites'][0]['name'], 'Suspicious complete name rejected' );
		no_secrets( $out, 'User text' ); }
	foreach ( array( 'Basic SEO checklist', 'Shop / Blog', 'Plain readable site', 'Rock &amp; Roll', 'Bearer|PIPE-FAKE' ) as $name ) {
		$wpdb->sites[1]['name'] = $name;
		$out                    = $class::execute_site_status();
		check( ( 'Bearer|PIPE-FAKE' === $name ? '' : html_entity_decode( $name ) ) === $out['sites'][0]['name'], 'Text backstop and plain names' ); }
	$wpdb->sites[1]['name']        = '<b>Readable 1</b>';
	$wpdb->sites[1]['last_backup'] = serialize( new stdClass() );
	check( null === $class::execute_site_status()['sites'][0]['last_backup'], 'Undecodable stored data becomes null' );
	$wpdb->sites[1]['last_backup'] = '1761782400';
	check( serialize( array( $wpdb->options_data, $wpdb->sites ) ) === $before, 'Options and site data unchanged' );
	$saved_sites = $wpdb->sites;
	$wpdb->sites = array();
	$empty       = $class::execute_site_status();
	check( array() === $empty['sites'] && 0 === $empty['total'], 'Empty site defaults' );
	no_secrets( $empty, 'Empty sites' );
	$wpdb->options_data = array();
	$empty              = $class::execute_providers();
	check( array() === $empty['providers'], 'Empty provider defaults' );
	no_secrets( $empty, 'Empty providers' );
	check( '' === ob_get_clean(), 'Callbacks produce no output' );
	check( 0 === $GLOBALS['raw_option_reads'], 'No raw option or credential reads' );
	check( array() === $GLOBALS['side_effects'], 'No mutation, remote, mail, decryption or cron calls' );
	check( 0 === filesize( $log ), 'No error log writes' );
	ini_set( 'error_log', $old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore this isolated process's original diagnostic destination.
	unlink( $log );
	$source = file_get_contents( $ability_file );
	check( ! preg_match( '/\b(?:error_log|log_info|log_debug|wp_remote_\w+|update_option|update_website_option|wp_mail|wp_schedule_\w+|decrypt_api_keys|get_api_key|file_get_contents|unserialize)\s*\(/', $source ), 'Source has no forbidden reads or side effects' );
	echo 'PASS ' . $GLOBALS['checks'] . " checks\n";
}
