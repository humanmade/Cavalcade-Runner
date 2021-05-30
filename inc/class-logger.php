<?php

namespace HM\Cavalcade\Runner;

use Exception;
use DateTime;

const LOG_TYPE_INFRA = 'infra';
const LOG_TYPE_APP = 'app';

class Logger
{
    protected $log_path;
    protected $log_handle;
    protected $version;

    public function __construct($log_path)
    {
        $this->log_path = $log_path;
        $this->log_handle = null;
    }

    public static function create($log_path)
    {
        $obj = new static($log_path);
        $obj->init_log_file();
        $obj->version = trim(file_get_contents(dirname(__DIR__) . '/VERSION'));
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
        $this->do_logging('DEBUG', LOG_TYPE_INFRA, $message, $values);
    }

    public function debug_app($message, $values = [])
    {
        $this->do_logging('DEBUG', LOG_TYPE_APP, $message, $values);
    }

    public function info($message, $values = [])
    {
        $this->do_logging('INFO', LOG_TYPE_INFRA, $message, $values);
    }

    public function info_app($message, $values = [])
    {
        $this->do_logging('INFO', LOG_TYPE_APP, $message, $values);
    }

    public function warn($message, $values = [])
    {
        $this->do_logging('WARN', LOG_TYPE_INFRA, $message, $values);
    }

    public function warn_app($message, $values = [])
    {
        $this->do_logging('WARN', LOG_TYPE_APP, $message, $values);
    }

    public function error($message, $values = [])
    {
        $this->do_logging('ERROR', LOG_TYPE_INFRA, $message, $values);
    }

    public function error_app($message, $values = [])
    {
        $this->do_logging('ERROR', LOG_TYPE_APP, $message, $values);
    }

    public function fatal($message, $values = [])
    {
        $this->do_logging('FATAL', LOG_TYPE_INFRA, $message, $values);
    }

    public function fatal_app($message, $values = [])
    {
        $this->do_logging('FATAL', LOG_TYPE_APP, $message, $values);
    }

    protected function do_logging($level, $type, $message, $values = [])
    {
        pcntl_signal_dispatch();
        $dt = new DateTime();
        $now = $dt->format('Y-m-d\TH:i:s.vO');
        $log = array_merge([
            'timestamp' => $now,
            'level' => $level,
            'type' => $type,
            'version' => $this->version,
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
