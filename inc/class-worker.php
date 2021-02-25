<?php

namespace HM\Cavalcade\Runner;

class Worker
{
    public $process;
    public $pipes = [];
    public $job;

    public $output = '';
    public $error_output = '';
    public $status = null;

    protected $log;
    protected $error_log_output;
    protected $error_log_file;

    public function __construct($process, $pipes, Job $job, $log, $error_log_file)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->job = $job;
        $this->log = $log;
        $this->error_log_file = $error_log_file;
    }

    public function is_done()
    {
        if (isset($this->status['running']) && !$this->status['running']) {
            return true;
        }

        $this->status = proc_get_status($this->process);
        $this->log->debug('worker status', ['job_id' => $this->job->id, 'status' => $this->status]);
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
        while ($data = fread($this->pipes[1], 1024)) {
            $this->output .= $data;
        }

        while ($data = fread($this->pipes[2], 1024)) {
            $this->error_output .= $data;
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
        $this->error_log_output = file_get_contents($this->error_log_file);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        unlink($this->error_log_file);
        proc_close($this->process);
        unset($this->process);

        return $this->status['exitcode'] === 0;
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
