<?php

namespace HM\Cavalcade\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use PDO;
use PDOException;

const LOOP_INTERVAL = 1;
const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

class Runner
{
    public $max_workers;
    public $wpcli_path;
    public $cleanup_interval;
    public $cleanup_delay;
    public $ip_check_interval;
    public $get_current_ip;
    public $hooks;
    public $eip;
    public $max_log_size;

    protected $db;
    protected $workers = [];
    protected $wp_path;
    protected $table_prefix;
    protected $table;
    protected $log;

    protected static $instance;

    public function __construct(
        $log,
        $max_workers,
        $wpcli_path,
        $cleanup_interval,
        $cleanup_delay,
        $wp_base_path,
        $get_current_ip,
        $ip_check_interval,
        $eip,
        $max_log_size
    ) {
        $this->max_workers = $max_workers;
        $this->wpcli_path = $wpcli_path;
        $this->cleanup_interval = $cleanup_interval;
        $this->cleanup_delay = $cleanup_delay;
        $this->wp_path = realpath($wp_base_path);
        $this->get_current_ip = $get_current_ip;
        $this->ip_check_interval = $ip_check_interval;
        $this->max_log_size = $max_log_size;
        $this->eip = $eip;
        $this->hooks = new Hooks();
        $this->log = $log;
    }

    /**
     * Get the singleton instance of the Runner.
     *
     * @return self
     */
    public static function instance(
        $log,
        $max_workers,
        $wpcli_path,
        $cleanup_interval,
        $cleanup_delay,
        $wp_base_path,
        $get_current_ip,
        $ip_check_interval,
        $eip,
        $max_log_size
    ) {
        if (empty(static::$instance)) {
            static::$instance = new static(
                $log,
                $max_workers,
                $wpcli_path,
                $cleanup_interval,
                $cleanup_delay,
                $wp_base_path,
                $get_current_ip,
                $ip_check_interval,
                $eip,
                $max_log_size,
            );
        }

        return static::$instance;
    }

    public function execute_query($query, $func)
    {
        try {
            $stmt = $this->db->prepare($query);
            return $func($stmt);
        } catch (PDOException $e) {
            $err = $e->errorInfo;

            ob_start();
            $stmt->debugDumpParams();
            $dump = ob_get_contents();
            ob_end_clean();

            $this->log->error('database error', [
                'dump' => $dump,
                'code' => $err[0],
                'driver_code' => $err[1],
                'error_message' => $err[2],
            ]);

            throw new Exception('database error', 0, $e);
        }
    }

    public function bootstrap()
    {
        // Check some requirements first
        if (!function_exists('pcntl_signal')) {
            throw new Exception('pcntl extension is required');
        }

        $config_path = $this->wp_path . '/wp-config.php';
        if (!file_exists($config_path)) {
            $config_path = realpath($this->wp_path . '/../wp-config.php');
            if (!file_exists($config_path)) {
                throw new Exception(sprintf(
                    'Could not find config file at %s',
                    $this->wp_path . '/wp-config.php or next level up.'
                ));
            }
        }

        // Load WP config
        define('ABSPATH', dirname(__DIR__) . '/fakewp/');
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'cavalcade.example';
        }

        include $config_path;
        $this->table_prefix = isset($table_prefix) ? $table_prefix : 'wp_';
        $this->table = $this->table_prefix . 'cavalcade_jobs';

        $this->connect_to_db();

        $this->upgrade_db();

        $this->cleanup_abandoned();
    }

    protected function upgrade_db()
    {
        $output = $retval = null;
        exec("$this->wpcli_path --path=$this->wp_path cavalcade upgrade", $output, $retval);
        if ($retval === 0) {
            $this->log->info('wp cavalcade upgrade execution succeeded', [
                'output' => $output,
                'retval' => $retval,
            ]);
        } else {
            $this->log->fatal('wp cavalcade upgrade failed', [
                'output' => $output,
                'retval' => $retval,
            ]);
            throw new Exception('wp cavalcad upgrade error');
        }
    }

    public function cleanup()
    {
        $expired = new DateTime('now', new DateTimeZone('UTC'));
        $expired->sub(new DateInterval("PT{$this->cleanup_delay}S"));
        $expired_str = $expired->format(MYSQL_DATE_FORMAT);

        try {
            $this->execute_query(
                "DELETE FROM `$this->table`
                 WHERE
                   (`deleted_at` < :expired1 AND `status` IN ('done', 'waiting'))
                 OR
                   (`finished_at` < :expired2 AND `status` = 'done')",
                function ($stmt) use ($expired_str) {
                    $stmt->bindValue(':expired1', $expired_str);
                    $stmt->bindValue(':expired2', $expired_str);
                    $stmt->execute();
                    $count = $stmt->rowCount();

                    $this->log->debug('db cleaned up', ['deleted_rows' => $count]);
                },
            );
        } catch (Exception $e) {
            $this->log->error('cleanup failed', ['ex_message' => $e->getMessage()]);
            sleep(10); // throttle
            // keep this process running
        }
    }

    public function cleanup_abandoned()
    {
        $this->execute_query(
            "SELECT * FROM `$this->table` WHERE `status` = 'running'",
            function ($stmt) {
                $stmt->execute();
                while (true) {
                    $job = $stmt->fetchObject(__NAMESPACE__ . '\\Job', [
                        $this->db,
                        $this->table_prefix,
                        $this->log,
                    ]);
                    if ($job === false) {
                        break;
                    }

                    $this->log->error('abandoned worker found', Job::log_values($job));
                    $job->mark_done();
                }
            },
        );
    }

    public function run()
    {
        pcntl_signal(SIGTERM, [$this, 'terminate']);
        pcntl_signal(SIGINT, [$this, 'terminate']);
        pcntl_signal(SIGQUIT, [$this, 'terminate']);

        $this->hooks->run('Runner.run.before');

        $prev_ip_check = $prev_cleanup = time();
        while (true) {
            pcntl_signal_dispatch();
            $this->hooks->run('Runner.run.loop_start', $this);

            $now = time();

            if ($this->ip_check_interval <= $now - $prev_ip_check) {
                $prev_ip_check = $now;
                if ($this->eip !== ($this->get_current_ip)()) {
                    $this->log->info(
                        'EIP stolen: exiting like receiving SIGTERM',
                        ['eip' => $this->eip]
                    );
                    break;
                }
            }

            if ($this->cleanup_interval <= $now - $prev_cleanup) {
                $prev_cleanup = $now;
                $this->cleanup();
            }

            $this->check_workers();

            if (count($this->workers) === $this->max_workers) {
                $this->log->debug('out of workers');
                sleep(LOOP_INTERVAL);
                continue;
            }

            $job = $this->get_next_job();
            if (empty($job)) {
                sleep(LOOP_INTERVAL);
                continue;
            }

            $this->run_job($job);
        }

        $this->terminate(SIGTERM);
    }

    public function terminate($signal)
    {
        $this->hooks->run('Runner.terminate.will_terminate', $signal);

        printf(
            'Cavalcade received terminate signal (%s), shutting down %d worker(s)...' . PHP_EOL,
            $signal,
            count($this->workers)
        );
        // Wait and clean up
        while (!empty($this->workers)) {
            $this->check_workers();
            usleep(100000);
        }

        $this->hooks->run('Runner.terminate.terminated', $signal);

        unset($this->db);

        throw new SignalInterrupt('Terminated by signal', $signal);
    }

    public function get_wp_path()
    {
        return $this->wp_path;
    }

    protected function connect_to_db()
    {
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        // Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
        if (preg_match('#^[^:]*:(/.+)$#', DB_HOST, $matches)) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], DB_NAME, $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset);
        }

        $this->db = new PDO($dsn, DB_USER, DB_PASSWORD);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->db->exec('SET time_zone = "+00:00"');

        $this->hooks->run('Runner.connect_to_db.connected', $this->db);
    }

    protected function get_next_job()
    {
        try {
            return $this->execute_query(
                "SELECT * FROM `$this->table`
                 WHERE `nextrun` < NOW()
                 AND `status` = 'waiting'
                 ORDER BY `nextrun` ASC
                 LIMIT 1",
                function ($stmt) {
                    $stmt->execute();
                    return $stmt->fetchObject(__NAMESPACE__ . '\\Job', [
                        $this->db,
                        $this->table_prefix,
                        $this->log,
                    ]);
                },
            );
        } catch (Exception $e) {
            $this->log->error('failed to get next job', ['ex_message' => $e->getMessage()]);
            sleep(10); // throttle
            return false;
        }
    }

    protected function run_job($job)
    {
        try {
            try {
                $this->hooks->run('Runner.run_job.acquiring_lock', $this->db, $job);
                $has_lock = $job->acquire_lock();
                if (!$has_lock) {
                    return;
                }

                $error_log_file = tempnam('/tmp', 'cavalcade');
                if ($error_log_file === false) {
                    $this->log->error('failed to create tmp file');
                    throw new Exception('failed to create tmp file');
                }
                $command = $this->job_command($job, $error_log_file);
                $this->log->debug('preparing for worker', ['job_id' => $job->id, 'command' => $command]);

                $spec = [
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'], // stderr
                ];
                $process = proc_open($command, $spec, $pipes, $this->wp_path);

                if (!is_resource($process)) {
                    throw new Exception('unable to proc_open()');
                }

                // Disable blocking to allow partial stream reads before EOF.
                if (!stream_set_blocking($pipes[1], false)) {
                    throw new Exception('failed to set stdout to non-blocking');
                }
                if (!stream_set_blocking($pipes[2], false)) {
                    throw new Exception('failed to set stderr to non-blocking');
                }
            } finally {
                $worker = new Worker($process, $pipes, $job, $this->log, $error_log_file, $this->max_log_size);
            }
        } catch (Exception $e) {
            $this->log->error('exception during starting job', [
                'ex_message' => $e->getMessage(),
            ]);
            $worker->shutdown();
            try {
                $this->hooks->run('Runner.run_job.canceling_lock', $this->db, $job);
                $job->cancel_lock();
            } catch (Exception $e) {
                $this->log->error('failed to cancel lock', ['ex_message' => $e->getMessage()]);
            }
            sleep(10); // throttle
            return;
        }
        $this->workers[] = $worker;

        $this->log->debug('worker started', ['job_id' => $job->id]);
        $this->hooks->run('Runner.run_job.started', $worker, $job);
    }

    protected function job_command($job, $error_log_file)
    {
        $siteurl = $job->get_site_url();

        $command = "php -d error_log=$error_log_file $this->wpcli_path cavalcade run $job->id";

        if ($siteurl) {
            $command .= ' --url=' . escapeshellarg($siteurl);
        }

        return $this->hooks->run('Runner.job_command.command', $command, $job);
    }

    protected function check_workers()
    {
        if (empty($this->workers)) {
            return;
        }

        $pipes_stdout = $pipes_stderr = [];
        foreach ($this->workers as $id => $worker) {
            $pipes_stdout[$id] = $worker->pipes[1];
            $pipes_stderr[$id] = $worker->pipes[2];
        }

        $out_write = $out_except = [];
        $changed_stdout = stream_select($pipes_stdout, $out_write, $out_except, 0);
        if ($changed_stdout === false) {
            $this->log->warn('stream_select failed');
            return;
        }

        $err_write = $err_except = [];
        $changed_stderr = stream_select($pipes_stderr, $err_write, $err_except, 0);
        if ($changed_stderr === false) {
            $this->log->warn('stream_select failed');
            return;
        }

        if ($changed_stdout === 0 && $changed_stderr === 0) {
            return;
        }

        $changed_workers = array_unique(array_merge(array_keys($pipes_stdout), array_keys($pipes_stderr)));

        foreach ($changed_workers as $id) {
            $worker = $this->workers[$id];
            $worker->drain_pipes();
            if (!$worker->is_done()) {
                continue;
            }

            try {
                $this->hooks->run('Runner.check_workers.job_finishing', $this->db, $worker, $worker->job);
                if ($worker->shutdown()) {
                    $worker->job->mark_done();
                    $this->log->info('job completed', Worker::log_values($worker));
                    $this->hooks->run('Runner.check_workers.job_completed', $worker, $worker->job);
                } else {
                    $worker->job->mark_done();
                    $this->log->error(
                        'job failed: failed to shutdown worker',
                        Worker::log_values($worker),
                    );
                    $this->hooks->run('Runner.check_workers.job_failed', $worker, $worker->job);
                }
            } catch (Exception $e) {
                $this->log->error('failed to finish job properly', [
                    'ex_message' => $e->getMessage(),
                ]);
                sleep(10); // throttle
                // keep running
            } finally {
                unset($this->workers[$id]);
            }
        }
    }
}
