<?php
/**
 * MainWP Database Logs.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Class Logs
 */
class Log {

	/**
	 * Log_Manager
	 *
	 * @var manager Hold Log_Manager class
	 * */
	public $manager;

	/**
	 * Hold Current visitors IP Address.
	 *
	 * @var string Hold Current visitors IP Address.
	 * */
	private $ip_address;


	/**
	 * Log constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param Log_Manager $manager The main manager class.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
	}


	/**
	 * Log handler.
	 *
	 * @param Connector $connector         Connector responsible for logging the event.
	 * @param string    $message           sprintf-ready error message string.
	 * @param array     $args              sprintf (and extra) arguments to use.
	 * @param int       $site_id  Target site id.
	 * @param string    $context           Context of the event.
	 * @param string    $action            Action of the event.
	 * @param int|null  $state action status: null - N/A, 0 - failed, 1 - success.
	 * @param int       $user_id           User responsible for the event.
	 *
	 * @return bool|WP_Error True if updated, otherwise false|WP_Error
	 */
	public function log( $connector, $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		if ( is_null( $site_id ) ) {
			$site_id = 0;
		}

		$cron_tracking = apply_filters( 'mainwp_module_log_cron_tracking', true, $connector, $message, $args, $site_id, $context, $action, $user_id, $state );

		$author = new Log_Author( $user_id );

		$agent = $author->get_current_agent();

		// WP Cron tracking requires opt-in and WP Cron to be enabled.
		if ( ! $cron_tracking && 'wp_cron' === $agent ) {
				return false;
		}

		$user = new \WP_User( $user_id );

		$user_meta = array(
			'user_login' => (string) ! empty( $user->user_login ) ? $user->user_login : '',
			'agent'      => (string) $agent,
		);

		if ( 'wp_cli' === $agent && function_exists( 'posix_getuid' ) ) {
			$uid       = posix_getuid();
			$user_info = posix_getpwuid( $uid );

			$user_meta['system_user_id']   = (int) $uid;
			$user_meta['system_user_name'] = (string) $user_info['name'];
		}

		$dura = isset( $args['duration'] ) ? floatval( $args['duration'] ) : $this->manager->executor->get_exec_time();

		if ( isset( $args['duration'] ) ) {
			unset( $args['duration'] );
		}

		$dura_bulk = 1;
		if ( isset( $args['duration_bulk'] ) ) {
			$dura_bulk = intval( $args['duration_bulk'] );
			unset( $args['duration_bulk'] );
		}

		if ( empty( $dura_bulk ) ) {
			$dura_bulk = 1;
		}

		$dura = $dura / $dura_bulk;

		// Prevent any meta with null values from being logged.
		$logs_meta = array_filter(
			$args,
			function ( $e ) {
				return ! is_null( $e );
			}
		);

		// Add user meta to Log meta.
		$logs_meta['user_meta'] = $user_meta;

		$recordarr = array(
			'site_id'   => (int) $site_id,
			'user_id'   => (int) $user_id,
			'item'      => (string) vsprintf( $message, $args ),
			'connector' => (string) $connector,
			'context'   => (string) $context,
			'action'    => (string) $action,
			'duration'  => $dura,
			'created'   => time(),
			'state'     => $state,
			'meta'      => (array) $logs_meta,
		);

		if ( 0 === $recordarr['site_id'] ) {
			unset( $recordarr['site_id'] );
		}

		if ( null === $recordarr['state'] || '' === $recordarr['state'] ) {
			unset( $recordarr['state'] );
		}

		$result = $this->manager->db->insert( $recordarr );

		// This is helpful in development environments.
		// error_log( $this->debug_backtrace( $recordarr ) ); //phpcs:ignore -- development.

		return $result;
	}

	/**
	 * Helper function to send a full backtrace of calls to the PHP error log for debugging.
	 *
	 * @param array $recordarr Record argument array.
	 *
	 * @return string $output MainWP Pro Reports backtrace.
	 */
	public function debug_backtrace( $recordarr ) {
		// Record details.
		$message   = isset( $recordarr['item'] ) ? $recordarr['item'] : null;
		$author    = isset( $recordarr['author'] ) ? $recordarr['author'] : null;
		$connector = isset( $recordarr['connector'] ) ? $recordarr['connector'] : null;
		$context   = isset( $recordarr['context'] ) ? $recordarr['context'] : null;
		$action    = isset( $recordarr['action'] ) ? $recordarr['action'] : null;

		// Log meta.
		$logs_meta = isset( $recordarr['meta'] ) ? $recordarr['meta'] : null;

		unset( $logs_meta['user_meta'] );

		if ( $logs_meta ) {
			array_walk(
				$logs_meta,
				function ( &$value, $key ) {
					$value = sprintf( '%s: %s', $key, ( '' === $value ) ? 'null' : $value );
				}
			);
			$logs_meta = implode( ', ', $logs_meta );
		}

		// User meta.
		$user_meta = isset( $recordarr['meta']['user_meta'] ) ? $recordarr['meta']['user_meta'] : null;

		if ( $user_meta ) {
			array_walk(
				$user_meta,
				function ( &$value, $key ) {
					$value = sprintf( '%s: %s', $key, ( '' === $value ) ? 'null' : $value );
				}
			);

			$user_meta = implode( ', ', $user_meta );
		}

		// Debug backtrace.
		ob_start();

		// @codingStandardsIgnoreStart
		debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // Option to ignore args requires PHP 5.3.6
		// @codingStandardsIgnoreEnd

		$backtrace = ob_get_clean();
		$backtrace = array_values( array_filter( explode( "\n", $backtrace ) ) );

		$output = sprintf(
			"Pro Reports Debug Backtrace\n\n    Summary | %s\n     Author | %s\n  Connector | %s\n    Context | %s\n     Action | %s\nReports Meta | %s\nAuthor Meta | %s\n\n%s\n",
			$message,
			$author,
			$connector,
			$context,
			$action,
			$logs_meta,
			$user_meta,
			implode( "\n", $backtrace )
		);

		return $output;
	}
}
