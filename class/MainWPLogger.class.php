<?php

class MainWPLogger
{
    const DISABLED = -1;
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;

    private $logFileNamePrefix = 'mainwp';
    private $logFileNameSuffix = '.log';
//    private $logMaxFiles = 5; //todo: future add log rotation
    private $logMaxMB = 5;
    private $logDateFormat = 'Y-m-d G:i:s';

    private $logDirectory = null;
    private $logCurrentHandle = null;

    private $logPriority = MainWPLogger::DISABLED;

    private static $instance = null;

    /**
     * @return MainWPLogger
     */
    static function Instance()
    {
        if (MainWPLogger::$instance == null) MainWPLogger::$instance = new MainWPLogger();
        return MainWPLogger::$instance;
    }

    private function __construct()
    {
        $this->logDirectory = MainWPUtility::getMainWPDir();
        $this->logDirectory = $this->logDirectory[0];
    }

    public function debug($pText)
    {
        return $this->log($pText, self::DEBUG);
    }

    public function info($pText)
    {
        return $this->log($pText, self::INFO);
    }

    public function warning($pText)
    {
        return $this->log($pText, self::WARNING);
    }

    public function infoForWebsite($pWebsite, $pAction, $pMessage)
    {
        if (empty($pWebsite)) return false;

        return $this->log('[' . $pWebsite->name . '] [' . MainWPUtility::getNiceURL($pWebsite->url) . ']  ::' . $pAction . ':: ' . $pMessage, self::INFO);
    }

    public function warningForWebsite($pWebsite, $pAction, $pMessage)
    {
        if (empty($pWebsite)) return false;

        return $this->log('[' . $pWebsite->name . '] [' . MainWPUtility::getNiceURL($pWebsite->url) . ']  ::' . $pAction . ':: ' . $pMessage, self::WARNING);
    }

    private function log($pText, $pPriority)
    {
        if ($this->logPriority >= $pPriority)
        {
            if (!$this->logCurrentHandle)
            {
                $this->logCurrentFile = $this->logDirectory . $this->logFileNamePrefix . $this->logFileNameSuffix;
                $this->logCurrentHandle = fopen($this->logCurrentFile, 'a+');
            }

            if ($this->logCurrentHandle)
            {
                $time = date($this->logDateFormat);
                switch ($pPriority)
                {
                    case self::DEBUG:
                        $prefix = '[DEBUG] ';
                        break;
                    case self::INFO:
                        $prefix = '[INFO] ';
                        break;
                    case self::WARNING:
                        $prefix = '[WARNING] ';
                        break;
                    default:
                        $prefix = '[LOG]';
                }

                fwrite($this->logCurrentHandle, $time . ' ' . $prefix . ' ' . $pText . "\n");
            }

            if (filesize($this->logCurrentFile) >= ($this->logMaxMB * 1048576))
            {
                fseek($this->logCurrentHandle, 0);
                $newLogFile = $this->logCurrentFile . '.tmp';
                $newLogHandle = false;
                $chunkSize = filesize($this->logCurrentFile) - ($this->logMaxMB * 1048576);
                while (!feof($this->logCurrentHandle))
                {
                    $content = fread($this->logCurrentHandle, $chunkSize);

                    if ($newLogHandle)
                    {
                        fwrite($newLogHandle, $content);
                    }
                    else if ($pos = strrpos($content, "\n"))
                    {
                        if (!$newLogHandle) $newLogHandle = fopen($newLogFile, 'w+');
                        fwrite($newLogHandle, substr($content, $pos + 1));
                    }
                }

                if  ($newLogHandle)
                {
                    fclose($newLogHandle);
                    fclose($this->logCurrentHandle);
                    unlink($this->logCurrentFile);
                    rename($newLogFile, $this->logCurrentFile);
                }
            }

            return true;
        }

        return false;
    }

    function prepend($string, $filename) {
      $context = stream_context_create();
      $fp = fopen($filename, 'r', 1, $context);
      $tmpname = md5($string);
      file_put_contents($tmpname, $string);
      file_put_contents($tmpname, $fp, FILE_APPEND);
      fclose($fp);
      unlink($filename);
      rename($tmpname, $filename);
    }
}