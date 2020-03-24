<?php
namespace MainWP\Dashboard;

/**
 * MainWP Logger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write logging file
class MainWP_Logger {

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

	private $logFileNamePrefix = 'mainwp';
	private $logFileNameSuffix = '.log';
	private $logMaxMB          = 0.5;
	private $logDateFormat     = 'Y-m-d H:i:s';
	private $logDirectory      = null;
	private $logPriority       = self::DISABLED;
	private static $instance   = null;

	/**
	 * @return MainWP_Logger
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Logger();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->logDirectory = MainWP_Utility::get_mainwp_dir();
		$this->logDirectory = $this->logDirectory[0];

		$enabled = get_option( 'mainwp_actionlogs' );
		if ( false === $enabled ) {
			$enabled = self::DISABLED;
		}

		$this->set_log_priority( $enabled );
	}

	public function set_log_priority( $pLogPriority ) {
		$this->logPriority = $pLogPriority;
	}

	public function debug( $pText ) {
		return $this->log( $pText, self::DEBUG );
	}

	public function info( $pText ) {
		return $this->log( $pText, self::INFO );
	}

	public function warning( $pText ) {
		return $this->log( $pText, self::WARNING );
	}

	public function info_update( $pText ) {
		return $this->log( $pText, self::INFO_UPDATE );
	}

	public function debug_for_website( $pWebsite, $pAction, $pMessage ) {
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage, self::DEBUG );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage, self::DEBUG );
	}

	public function info_for_website( $pWebsite, $pAction, $pMessage ) {
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage, self::INFO );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage, self::INFO );
	}

	public function warning_for_website( $pWebsite, $pAction, $pMessage, $addStackTrace = true ) {
		$stackTrace = '';
		if ( $addStackTrace ) {
			ob_start();
			debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$stackTrace = "\n" . ob_get_clean();
		}
		if ( empty( $pWebsite ) ) {
			return $this->log( '[-] [-]  ::' . $pAction . ':: ' . $pMessage . $stackTrace, self::WARNING );
		}

		return $this->log( '[' . $pWebsite->name . '] [' . MainWP_Utility::get_nice_url( $pWebsite->url ) . ']  ::' . $pAction . ':: ' . $pMessage . $stackTrace, self::WARNING );
	}

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
				$time   = date( $this->logDateFormat );
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
						if ( file_exists($newLogFile) ) {
							rename( $newLogFile, $this->logCurrentFile );
						}
					}
				}
			}

			return true;
		}

		return false;
	}

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

	public function get_log_file() {
		return $this->logDirectory . $this->logFileNamePrefix . $this->logFileNameSuffix;
	}

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

	public static function clear_log() {
		$logFile = self::instance()->get_log_file();
		if ( ! @unlink( $logFile, 'r' ) ) {
			$fh = @fopen( $logFile, 'w' );
			if ( false === $fh ) {
				return;
			}

			@fclose( $fh );
		}
	}

	public static function show_log() {
		$logFile = self::instance()->get_log_file();
		$fh      = @fopen( $logFile, 'r' );
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

		@fclose( $fh );
	}

}
