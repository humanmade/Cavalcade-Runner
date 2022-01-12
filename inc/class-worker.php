<?php

namespace HM\Cavalcade\Runner;

class Worker
{
    public $process;
    public $pipes = [];
    public $job;

    protected $status = null;
    protected $max_log_size;
    protected $error_log_file;

    protected $output = '';
    protected $error_output = '';
    protected $error_log_output = '';

    protected $log;

    public function __construct($process, $pipes, Job $job, $log, $error_log_file, $max_log_size)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->job = $job;
        $this->log = $log;
        $this->error_log_file = $error_log_file;
        $this->max_log_size = $max_log_size;
    }

    public function log_values_full()
    {
        return $this->job->log_values_full() + [
            'stdout' => $this->get_stdout(),
            'stderr' => $this->get_stderr(),
            'error_log' => $this->get_error_log(),
            'proc' => $this->get_status(),
        ];
    }

    public function is_done()
    {
        if (isset($this->status['running']) && !$this->status['running']) {
            return true;
        }

        $this->status = proc_get_status($this->process);
        if ($this->status === false) {
            $this->log->error('proc_get_status() failed', $this->log_values_full());
            return true;
        }
        $this->log->debug_app('worker status', ['job_id' => $this->job->id, 'status' => $this->status]);
        return !$this->status['running'];
    }

    protected static function strip_shebang($str)
    {
        $shebang = "#!/usr/bin/env php\n";
        $len = strlen($shebang);
        return substr($str, 0, $len) === $shebang ? substr($str, $len) : $str;
    }

    /**
     * Drain stdout & stderr into properties.
     *
     * Draining the pipes is needed to avoid workers hanging when they hit the system pipe buffer limits.
     */
    public function drain_pipes()
    {
        while ($data = @fread($this->pipes[1], 1024)) {
            if ($data === false) {
                break;
            }
            $this->output .= substr(
                $data,
                0,
                $this->max_log_size - strlen($this->output),
            );
        }

        while ($data = @fread($this->pipes[2], 1024)) {
            if ($data === false) {
                break;
            }
            $this->error_output .= substr(
                $data,
                0,
                $this->max_log_size - strlen($this->error_output),
            );
        }
    }

    /**
     * Shut down the process
     *
     * @return bool Did the process run successfully?
     */
    public function shutdown()
    {
        $this->log->debug('worker shutting down', ['job_id' => $this->job->id]);

        $this->drain_pipes();
        $this->output = self::strip_shebang($this->output);
        $this->error_log_output = file_get_contents(
            $this->error_log_file,
            false,
            null,
            0,
            $this->max_log_size,
        );
        @fclose($this->pipes[1]);
        @fclose($this->pipes[2]);
        @unlink($this->error_log_file);
        @proc_close($this->process);
        unset($this->process);

        return isset($this->status['exitcode']) && $this->status['exitcode'] === 0;
    }

    public function get_stdout()
    {
        return $this->output;
    }

    public function get_stderr()
    {
        return $this->error_output;
    }

    public function get_error_log()
    {
        return $this->error_log_output;
    }

    public function get_status()
    {
        return $this->status;
    }
}
