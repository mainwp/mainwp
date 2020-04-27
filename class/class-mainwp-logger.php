<?php
/**
 * MainWP Logger
 *
 * For custom read/write logging file.
 */

namespace MainWP\Dashboard;

/**
 * MainWP Logger
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


	/** @var string Log file prefix. */
	private $logFileNamePrefix = 'mainwp';

	/** @var string Log file suffix. */
	private $logFileNameSuffix = '.log';

	/** @var integer Log file max size. */
	private $logMaxMB = 0.5;

	/** @var string Log file date format. */
	private $logDateFormat = 'Y-m-d H:i:s';

	/** @var string Log file output directory. */
	private $logDirectory = null;

	/** @var string Log file priority. */
	private $logPriority = self::DISABLED;

	/** @var mixed Log file Instance. */
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
	 * @return void
	 */
	private function __construct() {
		$this->logDirectory = MainWP_Utility::get_mainwp_dir();
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
	 * Sets the Log Priority.
	 *
	 * @param mixed $pLogPriority
	 */
	public function set_log_priority( $pLogPriority ) {
		$this->logPriority = $pLogPriority;
	}

	/**
	 * Method debug()
	 *
	 * Grab debug.
	 *
	 * @param mixed $pText
	 *
	 * @return mixed Debug info.
	 */
	public function debug( $pText ) {
		return $this->log( $pText, self::DEBUG );
	}

	/**
	 * Method info()
	 *
	 * Grab info.
	 *
	 * @param mixed $pText
	 *
	 * @return mixed Log Info.
	 */
	public function info( $pText ) {
		return $this->log( $pText, self::INFO );
	}

	/**
	 * Method warning()
	 *
	 * Grab warning information.
	 *
	 * @param mixed $pText
	 *
	 * @return mixed Warning info.
	 */
	public function warning( $pText ) {
		return $this->log( $pText, self::WARNING );
	}

	/**
	 * Method info_update()
	 *
	 * Grab Info Update
	 *
	 * @param mixed $pText
	 *
	 * @return mixed Log Info Update.
	 */
	public function info_update( $pText ) {
		return $this->log( $pText, self::INFO_UPDATE );
	}


	/**
	 * Method debug_for_website()
	 *
	 * Grab Website debug and info.
	 *
	 * @param mixed $pWebsite
	 * @param mixed $pAction
	 * @param mixed $pMessage
	 *
	 * @return mixed Website debug info.
	 */
	public function debug_for_website( $pWebsite, $pAction, $pMessage ) {
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage, self::DEBUG );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage, self::DEBUG );
	}

	/**
	 * Method infor_for_website()
	 *
	 * Grab Website Info.
	 *
	 * @param mixed $pWebsite
	 * @param mixed $pAction
	 * @param mixed $pMessage
	 *
	 * @return mixed Website Info.
	 */
	public function info_for_website( $pWebsite, $pAction, $pMessage ) {
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage, self::INFO );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage, self::INFO );
	}

	/**
	 * Method warning_for_website()
	 *
	 * Grab Website Warnings.
	 *
	 * @param mixed   $pWebsite
	 * @param mixed   $pAction
	 * @param mixed   $pMessage
	 * @param boolean $addStackTrace
	 *
	 * @return mixed Website Warnings.
	 */
	public function warning_for_website( $pWebsite, $pAction, $pMessage, $addStackTrace = true ) {
		$stackTrace = '';
		if ( $addStackTrace ) {
			ob_start();
			// phpcs:ignore -- for debugging.
			debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$stackTrace = "\n" . ob_get_clean();
		}
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage . $stackTrace, self::WARNING );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage . $stackTrace, self::WARNING );
	}


	/**
	 * Method log()
	 *
	 * Create Log File.
	 *
	 * @param mixed $pText
	 * @param mixed $pPriority
	 *
	 * @return booleen True|False Default is False.
	 */
	public function log( $pText, $pPriority ) {

		$do_log = false;
		if ( ( self::INFO_UPDATE == $pPriority && self::INFO_UPDATE == $this->logPriority ) ||
				( self::INFO_UPDATE != $pPriority && $this->logPriority >= $pPriority ) ) {
			$do_log = true;
		}

		if ( $do_log ) {
			$this->logCurrentFile = $this->logDirectory . $this->logFileNamePrefix . $this->logFileNameSuffix;
			$logCurrentHandle     = fopen( $this->logCurrentFile, 'a+' );

			if ( $logCurrentHandle ) {
				$time   = gmdate( $this->logDateFormat );
				$prefix = '[' . $this->get_log_text( $pPriority ) . ']';

				global $current_user;

				if ( ! empty( $current_user ) && ! empty( $current_user->user_login ) ) {
					$prefix .= ' [' . $current_user->user_login . ']';
				}

				fwrite( $logCurrentHandle, $time . ' ' . $prefix . ' ' . $pText . "\n" );
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
	 * @param mixed $string
	 * @param mixed $filename
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
	 * @param mixed $pPriority
	 *
	 * @return string LOG -OR- DISABLED|DEBUG|INFO|WARNING|INFO UPDATE
	 */
	public function get_log_text( $pPriority ) {
		switch ( $pPriority ) {
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
