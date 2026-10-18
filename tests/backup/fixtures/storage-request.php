<?php
/**
 * Literal handler exits need a separate process to preserve their response.
 *
 * @package MainWP\Dashboard\Tests
 */

use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Handler;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System;

$repo = dirname( __DIR__, 3 );
require $repo . '/vendor/autoload.php';
require getenv( 'WP_TESTS_DIR' ) . '/wp-tests-config.php';
define( 'MAINWP_MODULE_LOG_ENABLED', false );
define( 'DISABLE_WP_CRON', true );
require ABSPATH . 'wp-settings.php';
require_once $repo . '/mainwp.php';

// This subprocess loads the plugin after WordPress has fired plugins_loaded.
if ( ! function_exists( 'MainWP\\Dashboard\\mainwp_current_user_have_right' ) ) {
	MainWP_System::instance()->plugins_loaded();
}

$storage_action = $argv[1];
$scope          = json_decode( $argv[2], true );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
$user_id = wp_insert_user(
	array(
		'user_login' => uniqid( 'storage-access-' ),
		'user_pass'  => 'fixture-password',
		'role'       => 'administrator',
	)
);
if ( is_wp_error( $user_id ) ) {
	throw new RuntimeException( 'Could not create the handler user.' );
}
wp_set_current_user( $user_id );
$dir = sys_get_temp_dir() . '/' . uniqid( 'mainwp-storage-access-' );
mkdir( $dir );
$store          = MainWP_Backup_Storage_Destinations::instance();
$destination_id = $store->add_destination(
	array(
		'name'     => 'Shared destination',
		'provider' => 'local',
		'settings' => array(
			'base_path' => $dir,
			'password'  => 'fixture-secret',
		),
	)
);
$other          = $store->add_destination(
	array(
		'name'       => 'Default destination',
		'provider'   => 'local',
		'settings'   => array( 'base_path' => $dir ),
		'is_default' => 1,
	)
);
if ( $destination_id < 1 || $other < 1 ) {
	throw new RuntimeException( 'Could not create handler destinations.' );
}
$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
$tasks = MainWP_DB::instance()->get_table_name( 'wp_backup' );
$wpdb->insert(
	$tasks,
	array(
		'userid'       => $user_id,
		'name'         => 'Private task name',
		'schedule'     => 'daily',
		'type'         => 'full',
		'sites'        => '',
		'groups'       => '',
		'destinations' => wp_json_encode( array( $destination_id ) ),
	)
);
$http_calls = 0;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$http_calls ) {
		++$http_calls;
		// Consuming declined OAuth state exchanges an empty code, which cannot issue tokens.
		if ( 'https://api.dropboxapi.com/oauth2/token' === $url && 'authorization_code' === ( $args['body']['grant_type'] ?? '' ) && '' === ( $args['body']['code'] ?? '' ) ) {
			return new WP_Error( 'empty_oauth_code', 'No authorization code was supplied.' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token'  => 'fixture-access',
					'refresh_token' => 'fixture-refresh',
				)
			),
		);
	},
	10,
	3
);
$input = array(
	'id'         => $destination_id,
	'name'       => 'Changed destination',
	'provider'   => 'local',
	'is_default' => 1,
	'settings'   => array( 'base_path' => $dir ),
);
if ( in_array( $storage_action, array( 'oauth_callback', 'oauth_callback_declined' ), true ) ) {
	$pending = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
		array(
			'id'       => $destination_id,
			'name'     => 'Connected destination',
			'settings' => array(
				'app_key'    => 'fixture-key',
				'app_secret' => 'fixture-secret',
			),
		)
	);
	parse_str( wp_parse_url( $pending['authorize_url'], PHP_URL_QUERY ), $params );
	$_GET = array(
		'mainwp_dropbox_oauth' => '1',
		'state'                => $params['state'],
		'code'                 => 'fixture-code',
	);
	if ( 'oauth_callback_declined' === $storage_action ) {
		$_GET['error'] = 'access_denied';
	}
} elseif ( 'mainwp_dropbox_oauth_start' === $storage_action ) {
	$input['settings'] = array(
		'app_key'    => 'fixture-key',
		'app_secret' => 'fixture-secret',
	);
}
if ( 'mainwp_storage_delete_confirmed' === $storage_action ) {
	$storage_action     = 'mainwp_storage_delete';
	$input['confirmed'] = '1';
}
$actor = $argv[3] ?? 'N';
if ( str_starts_with( $actor, 'S' ) ) {
	add_role(
		'mainwp_storage_staff',
		'Storage staff',
		array(
			'read'     => true,
			'level_10' => true,
		)
	);
	$user = new WP_User( $user_id );
	$user->set_role( 'mainwp_storage_staff' );
	wp_set_current_user( 0 );
	wp_set_current_user( $user_id );
	if ( current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'Staff fixture must not hold manage_options.' );
	}
	add_filter(
		'mainwp_currentusercan',
		static function ( $allowed, $type, $cap ) use ( $actor, $scope ) {
			return 'site' === $type ? in_array( (int) $cap, (array) $scope, true ) : 'S+' === $actor && 'dashboard' === $type && 'edit_backup_tasks' === $cap;
		},
		10,
		3
	);
} elseif ( 'A' === $actor ) {
	add_filter( 'mainwp_currentusercan', '__return_true' );
	add_filter( 'mainwp_currentuserallowedaccesssites', static fn() => 'all' );
}
if ( mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) !== ( 'S-right' !== $actor ) ) {
	throw new RuntimeException( 'Storage fixture did not establish the requested Dashboard right.' );
}
$_POST    = $input + array( 'security' => wp_create_nonce( $storage_action ) );
$_REQUEST = $_POST;
if ( 'all' !== $scope ) {
	add_filter(
		'mainwp_currentuserallowedaccesssites',
		static function () use ( $scope ) {
			return $scope;
		}
	);
}
$snapshot = static function () use ( $wpdb, $table, $tasks, $destination_id ) {
	return array(
		'table' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ),
		'row'   => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $destination_id ), ARRAY_A ),
		'tasks' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $tasks ), ARRAY_A ),
	);
};
$before   = $snapshot();
$lookups  = 0;
$query    = static function ( $sql ) use ( $table, $tasks, &$lookups ) {
	if ( preg_match( '/\b(?:FROM|UPDATE|INTO)\s+`?(?:' . preg_quote( $table, '/' ) . '|' . preg_quote( $tasks, '/' ) . ')`?\b/i', $sql ) ) {
		++$lookups;
	}
	return $sql;
};
add_filter( 'query', $query );
// Core's shutdown flush would emit the handler body before the evidence envelope.
remove_all_actions( 'shutdown' );
ob_start();
register_shutdown_function(
	static function () use ( $wpdb, $snapshot, $before, $query, &$lookups, &$http_calls, $user_id, $dir, $store, $destination_id, $other ) {
		$body = ob_get_clean();
		remove_filter( 'query', $query );
		$after  = $snapshot();
		$notice = get_transient( 'mainwp_dropbox_oauth_notice_' . $user_id );
		$result = $notice ?: json_decode( $body, true );
		// Database rollback cannot retire credential key files created by the fixture.
		$store->delete_destination( $destination_id );
		$store->delete_destination( $other );
		if ( isset( $result['authorize_url'] ) ) {
			parse_str( wp_parse_url( $result['authorize_url'], PHP_URL_QUERY ), $oauth );
		}
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ( $oauth['state'] ?? '' );
		if ( '' !== $state ) {
			$key      = 'mainwp_dropbox_oauth_' . $state;
			$envelope = get_transient( $key );
			if ( is_string( $envelope ) ) {
				$data = json_decode( substr( $envelope, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
				MainWP_Credential_Storage::retire_key_file_if_changed( (string) ( $data['file_key'] ?? '' ), '' );
			}
			delete_transient( $key );
		}
		$wpdb->query( 'ROLLBACK' );
		array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
		rmdir( $dir );
		echo wp_json_encode(
			array(
				'response'   => $result,
				'before'     => $before,
				'after'      => $after,
				'lookups'    => $lookups,
				'http_calls' => $http_calls,
			)
		);
	}
);
if ( in_array( $storage_action, array( 'oauth_callback', 'oauth_callback_declined' ), true ) ) {
	MainWP_Backup_Storage_Handler::instance()->handle_dropbox_oauth_callback();
} else {
	$method = 'ajax_' . substr( $storage_action, strlen( 'mainwp_' ) );
	MainWP_Backup_Storage_Handler::instance()->$method();
}
