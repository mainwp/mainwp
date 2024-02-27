<?php
/**
 * MainWP Logger
 *
 * For custom read/write logging file.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Logger
 *
 * @package MainWP\Dashboard
 */
class MainWP_Logger {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write logging file.

	const UPDATE_CHECK_LOG_PRIORITY    = 10;
	const EXECUTION_TIME_LOG_PRIORITY  = 15;
	const LOGS_AUTO_PURGE_LOG_PRIORITY = 16;
	const COST_TRACKER_LOG_PRIORITY    = 20230112;
	const API_BACKUPS_LOG_PRIORITY     = 20240130;

	const DISABLED = - 1;
	const LOG      = 0;
	const WARNING  = 1;
	const INFO     = 2;
	const DEBUG    = 3;

	const LOG_COLOR     = '#999999';
	const DEBUG_COLOR   = '#666666';
	const INFO_COLOR    = '#276f86';
	const WARNING_COLOR = '#9f3a38;';

	/**
	 * Private variable to hold time start.
	 *
	 * @var int
	 */
	private static $time_start = null;

	/**
	 * Private varibale to hold the log file prefix.
	 *
	 * @var string Default 'mainwp'
	 */
	private $logFileNamePrefix = 'mainwp';

	/**
	 * Private varibale to hold the log file suffix.
	 *
	 * @var string Default '.log'
	 */
	private $logFileNameSuffix = '.log';

	/**
	 * Private varibale to hold the log file max size.
	 *
	 * @var int Default 0.5
	 */
	private $logMaxMB = 0.5;

	/**
	 * Private varibale to hold the log file date format.
	 *
	 * @var string Default 'Y-m-d H:i:s'
	 */
	private $logDateFormat = 'Y-m-d H:i:s';

	/**
	 * Private varibale to hold the log file output directory.
	 *
	 * @var mixed Default null
	 */
	private $logDirectory = null;

	/**
	 * Private varibale to hold the log file priotrity.
	 *
	 * @var string Disabled
	 */
	private $logPriority = self::DISABLED;

	/**
	 * Private varibale to hold the log Specific priotrity.
	 *
	 * @var string Disabled
	 */
	private $logSpecific = 0;


	/**
	 * Private static varibale to hold the instance.
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Returns new MainWP_Logger instance.
	 *
	 * @return self MainWP_Logger
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Logger constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 */
	private function __construct() {

		$enabled  = $this->get_log_status();
		$specific = $this->get_log_specific();

		$enabled  = apply_filters( 'mainwp_log_status', $enabled );
		$specific = apply_filters( 'mainwp_log_specific', $specific );

		$this->set_log_priority( $enabled, $specific );
	}

	/**
	 * Method set_log_priority()
	 *
	 * Sets the log priority.
	 *
	 * @param mixed $logPriority Log priority value.
	 * @param mixed $spec_log Specific log.
	 */
	public function set_log_priority( $logPriority, $spec_log = 0 ) {
		$this->logPriority = (int) $logPriority;
		$this->logSpecific = (int) $spec_log; // 1 - specific log, 0 - not specific log.
	}

	/**
	 * Method get_log_status()
	 *
	 * Get log status.
	 *
	 * @return mixed $enabled log status.
	 */
	public function get_log_status() {
		$enabled = get_option( 'mainwp_actionlogs' );

		if ( false === $enabled ) {
			$sites_count = MainWP_DB::instance()->get_websites_count();
			if ( empty( $sites_count ) ) {
				$enabled = self::DEBUG;
				set_transient( 'mainwp_transient_action_logs', true, 2 * WEEK_IN_SECONDS );
			} elseif ( get_transient( 'mainwp_transient_action_logs' ) ) {
				$enabled = self::DEBUG;
			} else {
				$enabled = self::DISABLED;
			}
		}
		return $enabled;
	}


	/**
	 * Method get_log_specific()
	 *
	 * Get log specific status.
	 *
	 * @return mixed $enabled log status.
	 */
	public function get_log_specific() {
		return get_option( 'mainwp_specific_logs', 0 );
	}

	/**
	 * Method get_log_type_info()
	 *
	 * Get log type info.
	 *
	 * @param int $type Log type value.
	 * @param int $logcolor Log color value.
	 *
	 * @return int $currentColor log color code.
	 */
	public function get_log_type_info( $type, $logcolor ) {
		$currentColor = '';
		$prefix       = '';
		if ( self::DEBUG === $type || self::DEBUG === $logcolor ) {
			$currentColor = self::DEBUG_COLOR;
			$prefix       = '[DEBUG]';
		} elseif ( self::INFO === $type || self::INFO === $logcolor ) {
			$currentColor = self::INFO_COLOR;
			$prefix       = '[INFO]';
		} elseif ( self::WARNING === $type || self::WARNING === $logcolor ) {
			$currentColor = self::WARNING_COLOR;
			$prefix       = '[WARNING]';
		} elseif ( self::LOG === $type || self::LOG === $logcolor ) {
			$currentColor = self::LOG_COLOR;
			$prefix       = '[LOG]';
		}
		return array(
			'log_color'  => $currentColor,
			'log_prefix' => $prefix,
		);
	}

	/**
	 * Method debug()
	 *
	 * Grab debug.
	 *
	 * @param string $text Debug message text.
	 *
	 * @return string Log debug message.
	 */
	public function debug( $text ) {
		return $this->log( $text, self::DEBUG );
	}

	/**
	 * Method info()
	 *
	 * Grab info.
	 *
	 * @param string $text Info message text.
	 *
	 * @return string Log info message.
	 */
	public function info( $text ) {
		return $this->log( $text, self::INFO );
	}

	/**
	 * Method warning()
	 *
	 * Grab warning information.
	 *
	 * @param string $text Warning message text.
	 *
	 * @return string Log warning message.
	 */
	public function warning( $text ) {
		return $this->log( $text, self::WARNING );
	}

	/**
	 * Method actions()
	 *
	 * Grab actions information.
	 *
	 * @param string $text Warning message text.
	 * @param int    $priority priority message.
	 * @param int    $log_color Set color: 0 - LOG, 1 - WARNING, 2 - INFO, 3- DEBUG.
	 * @param bool   $forced forced logging.
	 *
	 * @return string Log warning message.
	 */
	public function log_action( $text, $priority, $log_color = 0, $forced = false ) {
		return $this->log( $text, $priority, $log_color, $forced );
	}


	/**
	 * Method log_update_check().
	 *
	 * @param string $text Log update check.
	 */
	public function log_update_check( $text = '' ) {
		$this->log_action( $text, self::UPDATE_CHECK_LOG_PRIORITY );
	}

	/**
	 * Method debug_for_website()
	 *
	 * Grab website debug and info.
	 *
	 * @param object $website Child site object.
	 * @param string $action Performed action.
	 * @param string $message Debug message.
	 *
	 * @return mixed Website debug info.
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public function debug_for_website( $website, $action, $message ) {
		if ( empty( $website ) ) {
			return $this->log( '[-] [-]  ::' . $action . ':: ' . $message, self::DEBUG );
		}

		return $this->log( '[' . $website->name . '] [' . MainWP_Utility::get_nice_url( $website->url ) . ']  ::' . $action . ':: ' . $message, self::DEBUG, 0, false, $website );
	}

	/**
	 * Method info_for_website()
	 *
	 * Grab Website Info.
	 *
	 * @param object $website Child site object.
	 * @param string $action Performed action.
	 * @param string $message Info message.
	 *
	 * @return mixed Website Info.
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public function info_for_website( $website, $action, $message ) {
		if ( empty( $website ) ) {
			return $this->log( '[-] [-]  ::' . $action . ':: ' . $message, self::INFO );
		}

		return $this->log( '[' . $website->name . '] [' . MainWP_Utility::get_nice_url( $website->url ) . ']  ::' . $action . ':: ' . $message, self::INFO );
	}

	/**
	 * Method warning_for_website()
	 *
	 * Grab Website Warnings.
	 *
	 * @param object $website Child site object.
	 * @param string $action Performed action.
	 * @param string $message Warning message.
	 * @param bool   $addStackTrace Add or Don't add stack trace.
	 *
	 * @return string Website warnings.
	 */
	public function warning_for_website( $website, $action, $message, $addStackTrace = true ) {
		$stackTrace = '';
		if ( $addStackTrace ) {
			ob_start();
			// phpcs:ignore -- for debugging.
			debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$stackTrace = "\n" . ob_get_clean();
		}
		if ( empty( $website ) ) {
			return $this->log( '[-] [-]  :: ' . $action . ' :: ' . $message . $stackTrace, self::WARNING );
		}

		return $this->log( '[' . $website->name . '] [' . MainWP_Utility::get_nice_url( $website->url ) . ']  ::' . $action . ':: ' . $message . $stackTrace, self::WARNING );
	}

	/**
	 * Method log_to_db()
	 *
	 * Log to database.
	 *
	 * @param string $text Log record text.
	 * @param int    $priority Set priority.
	 * @param int    $log_color Set color.
	 * @param bool   $forced forced logging.
	 * @param mixed  $website website object.
	 *
	 * @return bool true|false Default is False.
	 */
	private function log_to_db( $text, $priority, $log_color = 0, $forced = false, $website = false ) {

		if ( self::DISABLED === $this->logPriority ) {
			return;
		}

		$priority = (int) apply_filters( 'mainwp_log_to_db_priority', $priority, $website );

		$do_log = false;

		if ( 1 === $this->logSpecific ) { // 1 - specific log, 0 - not specific log.
			if ( $this->logPriority === $priority ) { // specific priority number saved setting.
				$do_log = true;
			}
		} elseif ( $this->logPriority >= $priority ) {
			$do_log = true;
		}

		$do_log = apply_filters( 'mainwp_log_do_to_db', $do_log, $website );

		$text = $this->prepare_log_info( $text );

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( 'CRON' !== strtoupper( substr( $text, 0, 4 ) ) ) {
				$text = 'CRON :: ' . $text;
			}
		}

		if ( $forced || $do_log ) {

			$time = gmdate( $this->logDateFormat );

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$user = '';
			if ( ! empty( $current_user ) && ! empty( $current_user->user_login ) ) {
				$user = $current_user->user_login;
			} elseif ( defined( 'WP_CLI' ) ) {
				$user = 'WP_CLI';
			} elseif ( defined( 'DOING_CRON' ) ) {
				$user = 'DOING_CRON';
			}

			$data = array();

			$data['log_content']   = $text;
			$data['log_user']      = $user;
			$data['log_type']      = $priority;
			$data['log_color']     = intval( $log_color );
			$data['log_timestamp'] = time();

			$data = apply_filters( 'mainwp_log_to_db_data', $data );

			MainWP_DB_Common::instance()->insert_action_log( $data );

			return true;
		}
		return false;
	}

	/**
	 * Method log()
	 *
	 * Create Log File.
	 *
	 * @param string $text Log record text.
	 * @param int    $priority Set priority.
	 * @param int    $log_color Set color.
	 * @param bool   $forced forced logging.
	 * @param mixed  $website Site object.
	 *
	 * @return bool true|false Default is False.
	 */
	private function log( $text, $priority,  $log_color = 0, $forced = false, $website = false ) { // phpcs:ignore -- complex function.

		if ( self::DISABLED === $this->logPriority ) {
			return;
		}

		$log_to_db = apply_filters( 'mainwp_logger_to_db', true, $website );

		if ( $log_to_db ) {
			return $this->log_to_db( $text, $priority, $log_color, $forced, $website );
		}

		$text = $this->prepare_log_info( $text );

		$do_log = false;
		if ( 1 === $this->logSpecific ) { // 1 - specific log, 0 - not specific log.
			if ( $this->logPriority === $priority ) { // specific priority number saved setting.
				$do_log = true;
			}
		} elseif ( $this->logPriority >= $priority ) {
			$do_log = true;
		}

		if ( $do_log ) {
			$this->logCurrentFile = $this->get_log_file();
			$logCurrentHandle     = fopen( $this->logCurrentFile, 'a+' );

			if ( $logCurrentHandle ) {
				$time   = gmdate( $this->logDateFormat );
				$prefix = '[' . $this->get_log_text( $priority ) . ']';

				/**
				 * Current user global.
				 *
				 * @global string
				 */
				global $current_user;

				if ( ! empty( $current_user ) && ! empty( $current_user->user_login ) ) {
					$prefix .= ' [administrator]';
				}

				fwrite( $logCurrentHandle, $time . ' ' . $prefix . ' ' . $text . "\n" );
				fclose( $logCurrentHandle );
			}

			if ( filesize( $this->logCurrentFile ) > ( $this->logMaxMB * 1048576 ) ) {
				$logCurrentHandle = fopen( $this->logCurrentFile, 'a+' );
				if ( $logCurrentHandle ) {
					fseek( $logCurrentHandle, 0 );
					$newLogFile   = $this->logCurrentFile . '.tmp';
					$newLogHandle = false;
					$chunkSize    = filesize( $this->logCurrentFile ) - ( $this->logMaxMB * 1048576 );
					while ( is_resource( $logCurrentHandle ) && ! feof( $logCurrentHandle ) && ( $chunkSize > 0 ) ) {
						$content = fread( $logCurrentHandle, $chunkSize );
						if ( false === $content ) {
							break;
						}
						$pos = strrpos( $content, "\n" );
						if ( $newLogHandle ) {
							fwrite( $newLogHandle, $content );
						} elseif ( $pos ) {
							if ( ! $newLogHandle ) {
								$newLogHandle = fopen( $newLogFile, 'w+' );
							}
							fwrite( $newLogHandle, substr( $content, $pos + 1 ) );
						}
					}

					if ( is_resource( $logCurrentHandle ) ) {
						fclose( $logCurrentHandle );
					}

					if ( $newLogHandle ) {
						fclose( $newLogHandle );
						wp_delete_file( $this->logCurrentFile );
						if ( file_exists( $newLogFile ) ) {
							rename( $newLogFile, $this->logCurrentFile );
						}
					}
				}
			}

			return true;
		}

		return false;
	}


	/**
	 * Method prepare_log_info()
	 *
	 * Prepare log data.
	 *
	 * @param mixed $data Log data.
	 *
	 * @return mixed $data filtered data.
	 */
	public function prepare_log_info( $data ) {
		$patterns[0]    = '/user=([^\&]+)\&/';
		$replacement[0] = 'user=xxxxxx&';
		$patterns[1]    = '/alt_user=([^\&]+)\&/';
		$replacement[1] = 'alt_user=xxxxxx&';
		$patterns[2]    = '/\&server=([^\&]+)\&/';
		$replacement[2] = '&server=xxxxxx&';
		$data           = preg_replace( $patterns, $replacement, $data );
		return $data;
	}

	/**
	 * Method prepend()
	 *
	 * Prepend content to log file.
	 *
	 * @param mixed $str Custom string.
	 * @param mixed $filename Filename.
	 */
	public function prepend( $str, $filename ) {
		$context = stream_context_create();
		$fp      = fopen( $filename, 'r', 1, $context );
		$tmpname = md5( $str );
		file_put_contents( $tmpname, $str );
		file_put_contents( $tmpname, $fp, FILE_APPEND );
		fclose( $fp );
		wp_delete_file( $filename );
		rename( $tmpname, $filename );
	}

	/**
	 * Method init_execution_time().
	 *
	 * @param string $time_index index for timer.
	 *
	 * Init execution time start value.
	 */
	public function init_execution_time( $time_index = '' ) {
		if ( null === self::$time_start || ! is_array( self::$time_start ) ) {
			self::$time_start = array( 'start' => microtime( true ) );
			$this->log_action( 'execution time :: init :: [start]', self::EXECUTION_TIME_LOG_PRIORITY );
		}

		if ( ! empty( $time_index ) && is_string( $time_index ) && 'start' !== $time_index ) {
			self::$time_start[ $time_index ] = microtime( true );
			$this->log_action( 'execution time :: init :: [' . $time_index . ']', self::EXECUTION_TIME_LOG_PRIORITY );
		}
	}

	/**
	 * Method log_execution_time().
	 *
	 * @param string $text Log record text.
	 *
	 * Log the execution time value.
	 */
	public function log_execution_time( $text = '' ) {
		$exec_time = $this->get_execution_time();
		$this->log_action( 'execution time :: ' . ( ! empty( $text ) ? (string) $text : '<empty>' ) . ' :: [time=' . round( $exec_time, 4 ) . '](seconds)', self::EXECUTION_TIME_LOG_PRIORITY );
	}


	/**
	 * Method get_execution_time().
	 *
	 * Get the execution time value.
	 *
	 * @param string $time_index Index for timer.
	 *
	 * @return int execution time.
	 */
	private function get_execution_time( $time_index = '' ) {

		if ( empty( self::$time_start ) ) {
			return 0;
		}

		$start = 0;
		if ( ! empty( $time_index ) ) {
			$start = is_array( self::$time_start ) && isset( self::$time_start[ $time_index ] ) ? self::$time_start[ $time_index ] : 0;
		}

		if ( empty( $start ) ) {
			$start = is_array( self::$time_start ) && isset( self::$time_start['start'] ) ? self::$time_start['start'] : 0;
		}

		if ( empty( $start ) ) {
			return 0;
		}
		return microtime( true ) - $start; // seconds.
	}

	/**
	 * Method get_log_file()
	 *
	 * Grab Log File.
	 *
	 * @return mixed Log File.
	 */
	public function get_log_file() {
		if ( empty( $this->logDirectory ) ) {
			$this->logDirectory = MainWP_System_Utility::get_mainwp_dir();
			$this->logDirectory = $this->logDirectory[0];
		}
		return $this->logDirectory . $this->logFileNamePrefix . $this->logFileNameSuffix;
	}

	/**
	 * Method get_log_text()
	 *
	 * Grab what type of log entry.
	 *
	 * @param mixed $priority Set priority.
	 *
	 * @return string LOG -OR- DISABLED|DEBUG|INFO|WARNING|INFO UPDATE
	 */
	public function get_log_text( $priority ) {
		switch ( $priority ) {
			case self::DISABLED:
				return 'DISABLED';
			case self::DEBUG:
				return 'DEBUG';
			case self::INFO:
				return 'INFO';
			case self::WARNING:
				return 'WARNING';
			default:
				return 'SPEC LOG';
		}
	}

	/**
	 * Method check_log_daily()
	 *
	 * Daily checks to clear the log file.
	 */
	public function check_log_daily() {
		$status = (int) $this->get_log_status();
		if ( 0 >= $status ) {
			return;
		}

		$today_m_y = date_i18n( 'd/m/Y' ); //phpcs:ignore -- local time.
		// one time per day.
		if ( get_option( 'mainwp_logger_check_daily' ) !== $today_m_y ) {
			$num_days = apply_filters( 'mainwp_logger_keep_days', 7 );
			MainWP_DB_Common::instance()->delete_action_log( $num_days );
			MainWP_Utility::update_option( 'mainwp_logger_check_daily', $today_m_y );

			$enabled_time = get_option( 'mainwp_actionlogs_enabled_timestamp', false );
			if ( false === $enabled_time ) {
				MainWP_Utility::update_option( 'mainwp_actionlogs_enabled_timestamp', time() );
			} elseif ( $enabled_time + $num_days * DAY_IN_SECONDS < time() ) {
				MainWP_Utility::update_option( 'mainwp_actionlogs', self::DISABLED );
			}
		}
	}

	/**
	 * Method clear_log_db()
	 *
	 * Clear the log file.
	 */
	public function clear_log_db() {
		MainWP_DB_Common::instance()->delete_action_log();
	}

	/**
	 * Method clear_log()
	 *
	 * Clear the log file.
	 */
	public function clear_log() {
		$logFile = $this->get_log_file();
		wp_delete_file( $logFile );
		$fh = fopen( $logFile, 'w' );
		if ( false === $fh ) {
			return;
		}
		fclose( $fh );
	}

	/**
	 * Method show_log_db()
	 *
	 * Grab log file and build output to screen.
	 */
	public function show_log_db() {

		echo '<div class="ui hidden divider"></div>';
		echo '<div class="ui divided padded relaxed list" local-datetime="' . date( 'Y-m-d H:i:s' ) . '">'; // phpcs:ignore -- local time.

		$rows = MainWP_DB::instance()->query( MainWP_DB_Common::instance()->get_sql_log() );

		$start_wrapper = '<span class="ui mini label mainwp-action-log-show-more">Click to See Response</span><div class="mainwp-action-log-site-response" style="display: none;">';
		$end_wrapper   = '</div>';

		while ( $rows && ( $row  = MainWP_DB::fetch_object( $rows ) ) ) {
			$type = $row->log_type;
			$line = $row->log_content;
			if ( 120 * 1024 < strlen( $line ) ) {
				$line = '[Data row too long]';
			}

			$time = gmdate( $this->logDateFormat, $row->log_timestamp );

			$showInfo     = $this->get_log_type_info( $row->log_type, $row->log_color );
			$currentColor = $showInfo['log_color'];

			$prefix = $time . ' ' . $showInfo['log_prefix'];

			$line = htmlentities( $line );

			if ( false !== strpos( $line, '[data-start]' ) ) {
				$line = str_replace( '[data-start]', $start_wrapper, $line );
				$line = str_replace( '[data-end]', $end_wrapper, $line );
			}

			echo '<div class="item" style="color:' . esc_html( $currentColor ) . '"><div class="mainwpactionlogsline">';

			echo $prefix . ' ' . $line; // phpcs:ignore WordPress.Security.EscapeOutput

			echo '</div></div>';
		}

		echo '</div></div>';

		echo '</div>';

		?>
		<div class="ui large modal" id="mainwp-action-log-response-modal">
			<i class="close icon mainwp-reload"></i>
			<div class="header"><?php esc_html_e( 'Child Site Response', 'mainwp' ); ?></div>
			<div class="content">
				<div class="ui info message"><?php esc_html_e( 'To see the response in a more readable way, you can copy it and paste it into some HTML render tool, such as Codepen.io.', 'mainwp' ); ?>
				</div>
			</div>
			<div class="scrolling content content-response"></div>
			<div class="actions">
				<button class="ui green button mainwp-response-copy-button"><?php esc_html_e( 'Copy Response', 'mainwp' ); ?></button>				
			</div>
		</div>
		<?php
	}

	/**
	 * Method show_log_file()
	 *
	 * Grab log file and build output to screen.
	 */
	public function show_log_file() {
		$logFile = $this->get_log_file();

		if ( ! file_exists( $logFile ) ) {
			return;
		}

		$fh = fopen( $logFile, 'r' );
		if ( false === $fh ) {
			return;
		}

		$previousColor            = '';
		$fontOpen                 = false;
		$firstLinePassedProcessed = false;
		echo '<div class="ui hidden divider"></div>';
		echo '<div class="ui divided padded relaxed list">';
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$currentColor = $previousColor;
			if ( stristr( $line, '[DEBUG]' ) ) {
				$currentColor    = self::DEBUG_COLOR;
				$firstLinePassed = true;
			} elseif ( stristr( $line, '[INFO]' ) ) {
				$currentColor    = self::INFO_COLOR;
				$firstLinePassed = true;
			} elseif ( stristr( $line, '[WARNING]' ) ) {
				$currentColor    = self::WARNING_COLOR;
				$firstLinePassed = true;
			} elseif ( stristr( $line, '[LOG]' ) ) {
				$currentColor    = self::LOG_COLOR;
				$firstLinePassed = true;
			} else {
				$firstLinePassed = false;
			}

			if ( $firstLinePassedProcessed && ! $firstLinePassed ) {
				echo ' <span class="ui mini label mainwp-action-log-show-more">Click to See Response</span></div><div class="mainwp-action-log-site-response" style="display: none;">';
			}

			$firstLinePassedProcessed = $firstLinePassed;

			if ( $currentColor !== $previousColor ) {
				if ( $fontOpen ) {
					echo '</div></div>';
				}

				echo '<div class="item" style="color:' . esc_html( $currentColor ) . '"><div class="mainwpactionlogsline">';
				$fontOpen = true;
			}

			echo esc_html( htmlentities( $line ) );
		}

		if ( $fontOpen ) {
			echo '</div></div>';
		}

		echo '</div>';

		?>
		<div class="ui large modal" id="mainwp-action-log-response-modal">
			<i class="close icon mainwp-reload"></i>
			<div class="header"><?php esc_html_e( 'Child Site Response', 'mainwp' ); ?></div>
			<div class="content">
				<div class="ui info message"><?php esc_html_e( 'To see the response in a more readable way, you can copy it and paste it into some HTML render tool, such as Codepen.io.', 'mainwp' ); ?>
				</div>
			</div>
			<div class="scrolling content content-response"></div>
			<div class="actions">
				<button class="ui green button mainwp-response-copy-button"><?php esc_html_e( 'Copy Response', 'mainwp' ); ?></button>				
			</div>
		</div>
		<?php

		fclose( $fh );
	}
}
