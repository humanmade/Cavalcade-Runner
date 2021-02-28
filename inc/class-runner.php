<?php

namespace HM\Cavalcade\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use PDO;

const LOOP_INTERVAL = 1;
const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

class Runner
{
    public $max_workers;
    public $wpcli_path;
    public $cleanup_interval;
    public $cleanup_delay;

    /**
     * Hook system for the Runner.
     *
     * @var Hooks
     */
    public $hooks;

    protected $db;
    protected $workers = [];
    protected $wp_path;
    protected $log;

    /**
     * Instance of the runner.
     *
     * @var self
     */
    protected static $instance;

    public function __construct(
        $log,
        $max_workers,
        $wpcli_path,
        $cleanup_interval,
        $cleanup_delay,
        $wp_base_path
    ) {
        $this->max_workers = $max_workers;
        $this->wpcli_path = $wpcli_path;
        $this->cleanup_interval = $cleanup_interval;
        $this->cleanup_delay = $cleanup_delay;
        $this->wp_path = realpath($wp_base_path);
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
        $wp_base_path
    ) {
        if (empty(static::$instance)) {
            static::$instance = new static(
                $log,
                $max_workers,
                $wpcli_path,
                $cleanup_interval,
                $cleanup_delay,
                $wp_base_path,
            );
        }

        return static::$instance;
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

        /**
         * Filter the table prefix from the configuration.
         *
         * @param string $table_prefix Table prefix to use for Cavalcade.
         */
        $this->table_prefix = $this->hooks->run('Runner.bootstrap.table_prefix', $this->table_prefix);

        // Connect to the database!
        $this->connect_to_db();

        $this->upgrade_db();
    }

    protected function upgrade_db()
    {
        $output = $retval = null;
        exec("$this->wpcli_path --path=$this->wp_path cavalcade upgrade", $output, $retval);
        $this->log->info('wp cavalcade upgrade executed', [
            'output' => $output,
            'retval' => $retval,
        ]);
    }

    public function cleanup()
    {
        $expired = new DateTime('now', new DateTimeZone('UTC'));
        $expired->sub(new DateInterval("PT{$this->cleanup_delay}S"));

        $query = "DELETE FROM {$this->table_prefix}cavalcade_jobs
                  WHERE
                      (deleted_at < :expired1 AND status IN ('completed', 'waiting', 'failed'))
                    OR
                      (finished_at < :expired2 AND status IN ('completed', 'failed'))";
        $statement = $this->db->prepare($query);
        $expired_str = $expired->format(MYSQL_DATE_FORMAT);
        $statement->bindValue(':expired1', $expired_str);
        $statement->bindValue(':expired2', $expired_str);
        $statement->execute();
        $count = $statement->rowCount();

        $this->log->debug('db cleaned up', ['deleted_rows' => $count]);
    }

    public function run()
    {
        pcntl_signal(SIGTERM, [$this, 'terminate']);
        pcntl_signal(SIGINT, [$this, 'terminate']);
        pcntl_signal(SIGQUIT, [$this, 'terminate']);

        $this->hooks->run('Runner.run.before');

        $prev_cleanup = time();
        while (true) {
            pcntl_signal_dispatch();
            $this->hooks->run('Runner.run.loop_start', $this);

            $now = time();
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

            try {
                $this->run_job($job);
            } catch (Exception $e) {
                $this->log->error('unable to run job', [
                    'reason' => $e->getMessage(),
                    'job_id' => intval($job->id),
                    'hook' => $job->hook,
                    'args' => $job->args,
                ]);
                $job->mark_failed($e->getMessage());
                break;
            }
        }

        $this->terminate(SIGTERM);
    }

    public function terminate($signal)
    {
        /**
         * Action before terminating workers.
         *
         * Use this to change the cleanup process.
         *
         * @param int $signal Signal received that caused termination.
         */
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

        /**
         * Action after terminating workers.
         *
         * Use this to run final shutdown commands while still connected to the database.
         *
         * @param int $signal Signal received that caused termination.
         */
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
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8';

        // Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
        if (preg_match('#^[^:]*:(/.+)$#', DB_HOST, $matches)) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], DB_NAME, $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset);
        }

        /**
         * Filter for PDO DSN.
         *
         * @param string $dsn DSN passed to PDO.
         * @param string $host Database host from config.
         * @param string $name Database name from config.
         * @param string $charset Character set from config, or default of 'utf8'
         */
        $dsn = $this->hooks->run('Runner.connect_to_db.dsn', $dsn, DB_HOST, DB_NAME, $charset);

        /**
         * Filter for PDO options.
         *
         * @param array $options Options to pass to PDO.
         * @param string $dsn DSN for the connection.
         * @param string $user User for the connection
         * @param string $password Password for the connection.
         */
        $options = $this->hooks->run('Runner.connect_to_db.options', [], $dsn, DB_USER, DB_PASSWORD);
        $this->db = new PDO($dsn, DB_USER, DB_PASSWORD, $options);

        // Set it up just how we like it
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->db->exec('SET time_zone = "+00:00"');

        /**
         * Action after connecting to the database.
         *
         * Use the PDO object to set additional attributes as needed.
         *
         * @param PDO $db PDO database connection.
         */
        $this->hooks->run('Runner.connect_to_db.connected', $this->db);
    }

    /**
     * Get next job to run
     *
     * @return stdClass|null
     */
    protected function get_next_job()
    {
        $query = "SELECT * FROM {$this->table_prefix}cavalcade_jobs
                  WHERE nextrun < NOW()
                  AND status = \"waiting\"
                  ORDER BY nextrun ASC
                  LIMIT 1";

        /**
         * Filter for the next job query.
         *
         * @param string $query Database query for the next job.
         */
        $query = $this->hooks->run('Runner.get_next_job.query', $query);

        $statement = $this->db->prepare($query);
        $statement->execute();

        $data = $statement->fetchObject(__NAMESPACE__ . '\\Job', [$this->db, $this->table_prefix]);
        /**
         * Filter for the next job.
         *
         * @param Job $data Next job to be run.
         */
        return $this->hooks->run('Runner.get_next_job.job', $data);
    }

    protected function run_job($job)
    {
        $has_lock = $job->acquire_lock();
        if (!$has_lock) {
            return;
        }

        $error_log_file = tempnam('/tmp', 'cavalcade');
        $command = $this->job_command($job, $error_log_file);
        $this->log->debug('preparing for worker', ['job_id' => $job->id, 'command' => $command]);

        $spec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        $process = proc_open($command, $spec, $pipes, $this->wp_path);

        if (!is_resource($process)) {
            throw new Exception('Unable to proc_open.');
        }

        // Disable blocking to allow partial stream reads before EOF.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $worker = new Worker($process, $pipes, $job, $this->log, $error_log_file);
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

        /**
         * Filter for the command to be run for the job.
         *
         * @param string $command Full shell command to be run to start the job.
         * @param Job $job Job to be run.
         */
        return $this->hooks->run('Runner.get_job_command.command', $command, $job);
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

        $dummy_a = $dummy_b = null;

        $changed_stdout = stream_select($pipes_stdout, $dummy_a, $dummy_b, 0);
        if ($changed_stdout === false) {
            // An error occured!
            return;
        }

        $changed_stderr = stream_select($pipes_stderr, $dummy_a, $dummy_b, 0);
        if ($changed_stderr === false) {
            // An error occured!
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

            if ($worker->shutdown()) {
                $worker->job->mark_completed();
                $this->log->job_completed($worker);
                $this->hooks->run('Runner.check_workers.job_completed', $worker, $worker->job);
            } else {
                $worker->job->mark_failed();
                $this->log->job_failed($worker, 'failed to shutdown worker');
                $this->hooks->run('Runner.check_workers.job_failed', $worker, $worker->job);
            }

            unset($this->workers[$id]);
        }
    }
}
