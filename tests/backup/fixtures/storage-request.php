<?php
/**
 * Literal handler exits need a separate process to preserve their response.
 *
 * @package MainWP\Dashboard\Tests
 */

use MainWP\Dashboard\MainWP_Backup_Retention_Protection;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Handler;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;
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
$pcloud_flow    = str_contains( $storage_action, 'pcloud' );
$connect_case   = str_starts_with( $storage_action, 'mainwp_pcloud_connect' ) ? $storage_action : '';
$app_case       = str_starts_with( $storage_action, 'pcloud_app_' ) ? $storage_action : '';
if ( $pcloud_flow ) {
	add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
}
require_once dirname( __DIR__ ) . '/class-backup-pcloud-transport-fixture.php';
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
$pcloud_state = array();
if ( str_starts_with( $connect_case, 'mainwp_pcloud_connect_eu' ) ) {
	$pcloud_state['email'] = 'eu-owner@example.com';
}
if ( 'mainwp_pcloud_connect_eu_no_email' === $connect_case ) {
	$pcloud_state['email'] = null;
} elseif ( 'mainwp_pcloud_connect_eu_invalid_email' === $connect_case ) {
	$pcloud_state['email'] = 'invalid';
}
$pcloud_transport = \MainWP\Dashboard\Tests\MainWP_PCloud_Transport_Fixture::transport( $pcloud_state );
$http_calls       = 0;
$exchanges        = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$http_calls, &$exchanges, $pcloud_transport, $connect_case ) {
		if ( str_ends_with( $url, '/oauth2_token' ) ) {
			++$http_calls;
			$exchanges[] = array(
				'url'  => $url,
				'body' => $args['body'],
			);
			$refused     = str_starts_with( $connect_case, 'mainwp_pcloud_connect_eu' ) && 'https://api.pcloud.com/oauth2_token' === $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					$refused ? array( 'result' => 2012 ) : array(
						'result'       => 0,
						'access_token' => 'fixture-access-token',
						'userid'       => 999,
						'locationid'   => str_starts_with( $connect_case, 'mainwp_pcloud_connect_eu' ) ? 2 : 1,
					)
				),
			);
		}
		if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_HOST ), '.pcloud.com' ) ) {
			++$http_calls;
			return $pcloud_transport( $pre, $args, $url );
		}
		++$http_calls;
		if ( 'https://api.dropboxapi.com/oauth2/token' === $url && 'authorization_code' === ( $args['body']['grant_type'] ?? '' ) && '' === ( $args['body']['code'] ?? '' ) ) {
			return new WP_Error( 'empty_oauth_code', 'No authorization code was supplied.' );
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token'  => 'fixture-access-token',
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

$oauth_key_path    = '';
$oauth_key_existed = false;
if ( str_starts_with( $storage_action, 'oauth_callback' ) ) {
	$pending = MainWP_Backup_Storage_Handler::start_dropbox_oauth(
		array(
			'id'       => $destination_id,
			'name'     => 'Connected destination',
			'settings' => array(
				'app_key'    => 'fixture-key',
				'app_secret' => 'fixture-secret', // gitleaks:allow -- test fixture value, not a secret.
			),
		)
	);
	parse_str( wp_parse_url( $pending['authorize_url'], PHP_URL_QUERY ), $params );
	$envelope          = get_transient( 'mainwp_dropbox_oauth_' . $params['state'] );
	$data              = json_decode( substr( $envelope, strlen( MainWP_Credential_Storage::ENVELOPE_PREFIX ) ), true );
	$oauth_key_path    = MainWP_Keys_Manager::get_keys_dir() . $data['file_key'];
	$oauth_key_existed = is_file( $oauth_key_path );

	$_GET = array(
		'mainwp_dropbox_oauth' => '1',
		'state'                => $params['state'],
		'code'                 => 'fixture-code',
	);
	if ( str_ends_with( $storage_action, '_declined' ) ) {
		$_GET['error'] = 'access_denied';
	}
} elseif ( 'mainwp_dropbox_oauth_start' === $storage_action ) {
	$input['settings'] = array(
		'app_key'    => 'fixture-key',
		'app_secret' => 'fixture-secret', // gitleaks:allow -- test fixture value, not a secret.
	);
}
if ( '' !== $connect_case ) {
	define( 'MAINWP_PCLOUD_CLIENT_ID', 'fixture-client' );
	if ( 'mainwp_pcloud_connect_partial_config' !== $connect_case ) {
		define( 'MAINWP_PCLOUD_CLIENT_SECRET', 'fixture-secret' ); // gitleaks:allow -- test fixture value, not a secret.
	}
	// Every field the browser could forge; only the pasted code may be used, and only once.
	$input          = array(
		'id'                 => 0,
		'name'               => 'Connected destination',
		'provider'           => 'pcloud',
		'is_default'         => 0,
		'settings'           => array(
			'base_path'          => 'Archive',
			'client_id'          => 'browser-client',
			'client_secret'      => 'browser-secret',
			'locationid'         => '2',
			'access_token'       => 'browser-token',
			'account_email'      => 'browser@example.com',
			'authorization_code' => 'fixture-paste-code',
		),
		'authorization_code' => 'fixture-paste-code',
	);
	if ( str_starts_with( $connect_case, 'mainwp_pcloud_connect_eu' ) ) {
		// A proved US identity requires confirmation before the EU account can replace it.
		$store->update_destination(
			$destination_id,
			array(
				'provider' => 'pcloud',
				'settings' => MainWP_Backup_Retention_Protection::with_pcloud_identity(
					array(
						'client_id'     => 'old-client',
						'access_token'  => 'old-token',
						'locationid'    => 1,
						'base_path'     => 'Archive',
						'account_email' => 'old-owner@example.com',
					),
					'101',
					'230807'
				),
			)
		);
		$input['id'] = $destination_id;
	}
	$storage_action = 'mainwp_pcloud_connect';
}
if ( '' !== $app_case ) {
	define( 'MAINWP_PCLOUD_CLIENT_ID', 'config-client' );
	if ( 'pcloud_app_id_only' !== $app_case ) {
		define( 'MAINWP_PCLOUD_CLIENT_SECRET', 'pcloud_app_secret_empty' === $app_case ? '' : 'config-secret' );
	}
}
if ( str_starts_with( $storage_action, 'pcloud_crafted_save' ) ) {
	$input['provider'] = 'pcloud';
	$input['settings'] = array(
		'client_id'    => 'fixture-key',
		'access_token' => 'fixture-access-token',
		'locationid'   => '1',
		'base_path'    => 'Archive',
	);
	if ( str_ends_with( $storage_action, '_new' ) ) {
		$input['id'] = 0;
	} else {
		$store->update_destination(
			$destination_id,
			array(
				'provider' => 'pcloud',
				'settings' => array_merge( $input['settings'], array( 'locationid' => 2 ) ),
			)
		);
	}
	$storage_action = 'mainwp_storage_save';
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
if ( 'mainwp_pcloud_connect_foreign_nonce' === $connect_case ) {
	wp_set_current_user( wp_insert_user( array( 'user_login' => uniqid( 'storage-other-' ), 'user_pass' => 'fixture-password', 'role' => 'administrator' ) ) );
	$nonce = wp_create_nonce( $storage_action );
	wp_set_current_user( $user_id );
} else {
	$nonce = wp_create_nonce( $storage_action );
}
$_POST    = $input + array( 'security' => $nonce );
$_REQUEST = $_POST;
if ( 'all' !== $scope ) {
	add_filter(
		'mainwp_currentuserallowedaccesssites',
		static function () use ( $scope ) {
			return $scope;
		}
	);
}
$snapshot                     = static function () use ( $wpdb, $table, $tasks, $destination_id ) {
	return array(
		'table' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ),
		'row'   => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $destination_id ), ARRAY_A ),
		'tasks' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $tasks ), ARRAY_A ),
	);
};
$before                       = $snapshot();
$pcloud_confirmation          = null;
$pcloud_pending_row_unchanged = false;
$lookups                      = 0;
$query                        = static function ( $sql ) use ( $table, $tasks, &$lookups ) {
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
	static function () use ( $wpdb, $snapshot, $before, $query, &$lookups, &$http_calls, &$exchanges, &$pcloud_confirmation, &$pcloud_pending_row_unchanged, $user_id, $dir, $store, $destination_id, $other, $oauth_key_path, $oauth_key_existed ) {
		$body = ob_get_clean();
		remove_filter( 'query', $query );
		$after                 = $snapshot();
		$notice                = get_transient( 'mainwp_dropbox_oauth_notice_' . $user_id );
		$result                = $notice ?: json_decode( $body, true );
		$stored                = $store->get_destination( isset( $result['id'] ) ? (int) $result['id'] : $destination_id );
		$stored_settings       = null !== $stored && 'pcloud' === $stored->provider ? $stored->settings : null;
		$pcloud_stash_consumed = isset( $pcloud_confirmation['stash_key'] ) && false === get_transient( 'mainwp_pcloud_connect_' . $pcloud_confirmation['stash_key'] );
		$code_copies           = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s OR option_value LIKE %s', $wpdb->options, '%fixture-paste-code%', '%fixture-paste-code%' ) )
			+ substr_count( (string) $body, 'fixture-paste-code' )
			+ substr_count( (string) wp_json_encode( $stored_settings ), 'fixture-paste-code' )
			+ substr_count( (string) wp_json_encode( $after ), 'fixture-paste-code' );
		if ( isset( $result['id'] ) && (int) $result['id'] !== $destination_id ) {
			$store->delete_destination( (int) $result['id'] );
		}
		clearstatcache( true, $oauth_key_path );
		$oauth_key_retired    = '' !== $oauth_key_path && ! is_file( $oauth_key_path );
		$oauth_state_consumed = false;
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

			$oauth_state_consumed = false === $envelope;
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
				'response'                     => $result,
				'stored_settings'              => $stored_settings,
				'before'                       => $before,
				'after'                        => $after,
				'lookups'                      => $lookups,
				'http_calls'                   => $http_calls,
				'exchanges'                    => $exchanges,
				'code_copies'                  => $code_copies,
				'oauth_state_consumed'         => $oauth_state_consumed,
				'oauth_key_existed'            => $oauth_key_existed,
				'oauth_key_retired'            => $oauth_key_retired,
				'pcloud_confirmation'          => $pcloud_confirmation,
				'pcloud_stash_posted'          => isset( $_POST['stash_key'] ) && ! isset( $_POST['authorization_code'] ),
				'pcloud_stash_consumed'        => $pcloud_stash_consumed,
				'pcloud_pending_row_unchanged' => $pcloud_pending_row_unchanged,
			)
		);
	}
);
if ( '' !== $app_case ) {
	ob_start();
	\MainWP\Dashboard\MainWP_Backup_Storage_View::render_form();
	$form = new DOMDocument();
	@$form->loadHTML( ob_get_clean() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- HTML5 markup warnings.
	$link = $form->getElementById( 'mainwp-storage-pcloud-connect' );
	echo wp_json_encode(
		array(
			'credentials'   => \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::app_credentials(),
			'authorize'     => \MainWP\Dashboard\MainWP_Backup_Storage_PCloud::authorize_url(),
			'link'          => array(
				'tag'      => $link->nodeName,
				'href'     => $link->getAttribute( 'href' ),
				'target'   => $link->getAttribute( 'target' ),
				'rel'      => $link->getAttribute( 'rel' ),
				'disabled' => $link->hasAttribute( 'disabled' ),
			),
			'form_secret'   => str_contains( $form->saveHTML(), 'config-secret' ),
		)
	);
} elseif ( in_array( $storage_action, array( 'oauth_callback', 'oauth_callback_declined' ), true ) ) {
	MainWP_Backup_Storage_Handler::instance()->handle_dropbox_oauth_callback();
} elseif ( 'mainwp_pcloud_connect' === $storage_action ) {
	MainWP_Backup_Storage_Handler::instance()->init();
	if ( str_starts_with( $connect_case, 'mainwp_pcloud_connect_eu' ) ) {
		$pcloud_confirmation          = MainWP_Backup_Storage_Handler::connect_pcloud( $input, $input['authorization_code'] );
		$pcloud_pending_row_unchanged = $before['row'] === $snapshot()['row'];
		if ( ! isset( $pcloud_confirmation['stash_key'] ) ) {
			echo wp_json_encode( $pcloud_confirmation );
			exit;
		}
		$_POST    = array(
			'id'        => $destination_id,
			'stash_key' => $pcloud_confirmation['stash_key'],
			'security'  => $nonce,
		);
		$_REQUEST = $_POST;
	}
	do_action( 'wp_ajax_mainwp_pcloud_connect' );
} else {
	$method = 'ajax_' . substr( $storage_action, strlen( 'mainwp_' ) );
	MainWP_Backup_Storage_Handler::instance()->$method();
}
