<?php

namespace HM\Cavalcade\Runner;

use Exception;
use DateTime;

class Logger
{
    protected $log_path;
    protected $log_handle;

    public function __construct($log_path)
    {
        $this->log_path = $log_path;
        $this->log_handle = null;
    }

    public static function create($log_path)
    {
        $obj = new static($log_path);
        $obj->init_log_file();
        return $obj;
    }

    public function init_log_file()
    {
        pcntl_signal(SIGUSR1, function ($signo) {
            if ($this->log_handle === null) {
                return;
            }

            $this->debug('USR1 signal received. reopening log file...', ['file' => $this->log_path]);

            if (!fclose($this->log_handle)) {
                throw new Exception(sprintf('failed to close log file: %s', $this->log_path));
            }

            $this->log_handle = fopen($this->log_path, 'a');
            if (!$this->log_handle) {
                throw new Exception(sprintf('failed to open log file: %s', $this->log_path));
            }

            $this->debug('reopened log file', ['file' => $this->log_path]);
        });

        $this->log_handle = fopen($this->log_path, 'a');
        if (!$this->log_handle) {
            throw new Exception(sprintf('failed to open log file: %s', $this->log_path));
        }
    }

    public function debug($message, $values = [])
    {
        $this->do_logging('DEBUG', $message, $values);
    }

    public function info($message, $values = [])
    {
        $this->do_logging('INFO', $message, $values);
    }

    public function warn($message, $values = [])
    {
        $this->do_logging('WARN', $message, $values);
    }

    public function error($message, $values = [])
    {
        $this->do_logging('ERROR', $message, $values);
    }

    public function fatal($message, $values = [])
    {
        $this->do_logging('FATAL', $message, $values);
    }

    protected function do_logging($level, $message, $values = [])
    {
        pcntl_signal_dispatch();
        $dt = new DateTime();
        $now = $dt->format('Y-m-d\TH:i:s.vO');
        $log = array_merge([
            'timestamp' => $now,
            'level' => $level,
            'message' => $message,
        ], $values);

        $json = json_encode(
            $log,
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        fwrite($this->log_handle, $json . "\n");
        fflush($this->log_handle);
    }
}
