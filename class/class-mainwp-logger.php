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
 * Class MainWP Logger
 */
class MainWP_Logger {

	// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write logging file.

	const DISABLED    = - 1;
	const LOG         = 0;
	const WARNING     = 1;
	const INFO        = 2;
	const DEBUG       = 3;
	const INFO_UPDATE = 10;

	const LOG_COLOR     = 'black';
	const DEBUG_COLOR   = 'gray';
	const INFO_COLOR    = 'gray';
	const WARNING_COLOR = 'red';

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
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Logger();
		}

		return self::$instance;
	}

	/**
	 * Method __construct()
	 *
	 * Constructor.
	 */
	private function __construct() {
		$this->logDirectory = MainWP_System_Utility::get_mainwp_dir();
		$this->logDirectory = $this->logDirectory[0];

		$enabled = get_option( 'mainwp_actionlogs' );
		if ( false === $enabled ) {
			$enabled = self::DISABLED;
		}

		$this->set_log_priority( $enabled );
	}

	/**
	 * Method set_log_priority()
	 *
	 * Sets the log priority.
	 *
	 * @param mixed $logPriority Log priority value.
	 */
	public function set_log_priority( $logPriority ) {
		$this->logPriority = $logPriority;
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
	 * Method info_update()
	 *
	 * Grab info update
	 *
	 * @param string $text Info Update message text.
	 *
	 * @return string Log info update message.
	 */
	public function info_update( $text ) {
		return $this->log( $text, self::INFO_UPDATE );
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
	 */
	public function debug_for_website( $website, $action, $message ) {
		if ( empty( $website ) ) {
			return $this->log( '[-] [-]  ::' . $action . ':: ' . $message, self::DEBUG );
		}

		return $this->log( '[' . $website->name . '] [' . MainWP_Utility::get_nice_url( $website->url ) . ']  ::' . $action . ':: ' . $message, self::DEBUG );
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
	 * @param object  $website Child site object.
	 * @param string  $action Performed action.
	 * @param string  $message Warning message.
	 * @param boolean $addStackTrace Add or Don't add stack trace.
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
			return $this->log( '[-] [-]  ::' . $action . ':: ' . $message . $stackTrace, self::WARNING );
		}

		return $this->log( '[' . $website->name . '] [' . MainWP_Utility::get_nice_url( $website->url ) . ']  ::' . $action . ':: ' . $message . $stackTrace, self::WARNING );
	}


	/**
	 * Method log()
	 *
	 * Create Log File.
	 *
	 * @param string $text Log record text.
	 * @param int    $priority Set priority.
	 *
	 * @return booleen True|False Default is False.
	 */
	public function log( $text, $priority ) {

		$do_log = false;
		if ( ( self::INFO_UPDATE == $priority && self::INFO_UPDATE == $this->logPriority ) ||
				( self::INFO_UPDATE != $priority && $this->logPriority >= $priority ) ) {
			$do_log = true;
		}

		if ( $do_log ) {
			$this->logCurrentFile = $this->logDirectory . $this->logFileNamePrefix . $this->logFileNameSuffix;
			$logCurrentHandle     = fopen( $this->logCurrentFile, 'a+' );

			if ( $logCurrentHandle ) {
				$time   = gmdate( $this->logDateFormat );
				$prefix = '[' . $this->get_log_text( $priority ) . ']';

				global $current_user;

				if ( ! empty( $current_user ) && ! empty( $current_user->user_login ) ) {
					$prefix .= ' [' . $current_user->user_login . ']';
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
						unlink( $this->logCurrentFile );
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
	 * Method prepend()
	 *
	 * Prepend content to log file.
	 *
	 * @param mixed $string Custom string.
	 * @param mixed $filename Filename.
	 */
	public function prepend( $string, $filename ) {
		$context = stream_context_create();
		$fp      = fopen( $filename, 'r', 1, $context );
		$tmpname = md5( $string );
		file_put_contents( $tmpname, $string );
		file_put_contents( $tmpname, $fp, FILE_APPEND );
		fclose( $fp );
		unlink( $filename );
		rename( $tmpname, $filename );
	}

	/**
	 * Method get_log_file()
	 *
	 * Grab Log File.
	 *
	 * @return mixed Log File.
	 */
	public function get_log_file() {
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
			case self::INFO_UPDATE:
				return 'INFO UPDATE';
			default:
				return 'LOG';
		}
	}

	/**
	 * Method clear_log()
	 *
	 * Clear the log file.
	 */
	public static function clear_log() {
		$logFile = self::instance()->get_log_file();
		if ( ! unlink( $logFile, 'r' ) ) {
			$fh = fopen( $logFile, 'w' );
			if ( false === $fh ) {
				return;
			}

			fclose( $fh );
		}
	}

	/**
	 * Method show_log()
	 *
	 * Grab log file and build output to screen.
	 */
	public static function show_log() {
		$logFile = self::instance()->get_log_file();
		$fh      = fopen( $logFile, 'r' );
		if ( false === $fh ) {
			return;
		}

		$previousColor            = '';
		$fontOpen                 = false;
		$firstLinePassedProcessed = false;
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
				echo ' <strong><span class="mainwp-green">[multiline, click to read full]</span></strong></div><div style="display: none;">';
			} else {
				echo '<br />';
			}
			$firstLinePassedProcessed = $firstLinePassed;

			if ( $currentColor != $previousColor ) {
				if ( $fontOpen ) {
					echo '</div></font>';
				}

				echo '<font color="' . $currentColor . '"><div class="mainwpactionlogsline">';
				$fontOpen = true;
			}

			echo htmlentities( $line );
		}

		if ( $fontOpen ) {
			echo '</div></font>';
		}

		fclose( $fh );
	}

}
