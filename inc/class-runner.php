<?php

namespace HM\Cavalcade\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use PDO;

const LOOP_INTERVAL = 1;
const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';
const MAINTENANCE_FILE = '.maintenance';

class Runner
{
    public $max_workers;
    public $wpcli_path;
    public $cleanup_interval;
    public $cleanup_delay;
    public $ip_check_interval;
    public $get_current_ips;
    public $hooks;
    public $eip;
    public $max_log_size;
    public $state_path;

    protected $pdoclass;
    protected $db;
    protected $workers = [];
    protected $wp_path;
    protected $maintenance_path;
    protected $table_prefix;
    protected $table;
    protected $state;
    protected $log;

    protected static $instance;

    public function __construct(
        $log,
        $pdoclass,
        $max_workers,
        $wpcli_path,
        $cleanup_interval,
        $cleanup_delay,
        $wp_base_path,
        $get_current_ips,
        $ip_check_interval,
        $eip,
        $max_log_size,
        $state_path
    ) {
        $this->pdoclass = $pdoclass;
        $this->max_workers = $max_workers;
        $this->wpcli_path = $wpcli_path;
        $this->cleanup_interval = $cleanup_interval;
        $this->cleanup_delay = $cleanup_delay;
        $this->wp_path = realpath($wp_base_path);
        $this->maintenance_path = $this->wp_path . '/' . MAINTENANCE_FILE;
        $this->get_current_ips = $get_current_ips;
        $this->ip_check_interval = $ip_check_interval;
        $this->max_log_size = $max_log_size;
        $this->state_path = $state_path;
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
        $pdoclass,
        $max_workers,
        $wpcli_path,
        $cleanup_interval,
        $cleanup_delay,
        $wp_base_path,
        $get_current_ips,
        $ip_check_interval,
        $eip,
        $max_log_size,
        $state_path
    ) {
        if (empty(static::$instance)) {
            static::$instance = new static(
                $log,
                $pdoclass,
                $max_workers,
                $wpcli_path,
                $cleanup_interval,
                $cleanup_delay,
                $wp_base_path,
                $get_current_ips,
                $ip_check_interval,
                $eip,
                $max_log_size,
                $state_path
            );
        }

        return static::$instance;
    }

    private function save_current_state()
    {
        $json = json_encode($this->state);
        if ($json === false) {
            throw new Exception('failed to encode state: ' . var_export($this->state, true));
        };

        if (file_put_contents($this->state_path, $json) === false) {
            throw new Exception('failed to write to state file: ' . $this->state_path);
        }

        $this->log->info('state file updated', ['state' => var_export($this->state, true)]);
    }

    private function load_state()
    {
        if (!file_exists($this->state_path)) {
            $this->state = new \stdClass();
        } else {
            $state = json_decode(file_get_contents($this->state_path));
            if ($state === null) {
                throw new Exception('failed to load state from json file: ' . $this->state_path);
            }
            $this->state = $state;
        }

        if (!property_exists($this->state, 'schema_version')) {
            $this->state->schema_version = null;
        }
    }

    public function bootstrap()
    {
        $this->load_state();

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

        // Load configuration ONLY
        define('ABSPATH', dirname(__DIR__) . '/fakewp/');
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'cavalcade.example';
        }

        include $config_path;
        $this->table_prefix = isset($table_prefix) ? $table_prefix : 'wp_';
        $this->table = $this->table_prefix . 'cavalcade_jobs';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $collate = defined('DB_COLLATE') ? DB_COLLATE : 'utf8mb4_unicode_ci';

        $this->db = new DB(
            $this->log,
            $this->pdoclass,
            $charset,
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME
        );
        $this->db->connect();
        $this->hooks->run('Runner.connect_to_db.connected', $this->db->get_connection());

        $schema = new DBSchema(
            $this->log,
            $this->db,
            $this->table_prefix,
            $charset,
            $collate,
            $this->state->schema_version
        );
        $new_version = $schema->create_or_upgrade();
        if ($new_version !== null) {
            $this->state->schema_version = $new_version;
            $this->save_current_state();
        }
        $this->validate_schema();
        $this->cleanup_abandoned();
    }

    public function validate_schema()
    {
        $validate = function ($pred, $message) {
            if (!$pred) {
                throw new Exception("schema validation failed: $message");
            }
        };

        $schema = [
            'id' => [
                'Type' => 'bigint(20) unsigned',
                'Null' => 'NO',
                'Key' => 'PRI',
                'Default' => NULL,
                'Extra' => 'auto_increment',
            ],
            'site' => [
                'Type' => 'bigint(20) unsigned',
                'Null' => 'NO',
                'Key' => 'MUL',
                'Default' => NULL,
                'Extra' => '',
            ],
            'hook' => [
                'Type' => 'varchar(255)',
                'Null' => 'NO',
                'Key' => 'MUL',
                'Default' => NULL,
                'Extra' => '',
            ],
            'hook_instance' => [
                'Type' => 'varchar(255)',
                'Null' => 'NO',
                'Key' => '',
                'Default' => '',
                'Extra' => '',
            ],
            'args' => [
                'Type' => 'longtext',
                'Null' => 'NO',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'args_digest' => [
                'Type' => 'char(64)',
                'Null' => 'NO',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'nextrun' => [
                'Type' => 'datetime',
                'Null' => 'NO',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'interval' => [
                'Type' => 'int(10) unsigned',
                'Null' => 'YES',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'status' => [
                'Type' => 'enum(\'waiting\',\'running\',\'done\')',
                'Null' => 'NO',
                'Key' => 'MUL',
                'Default' => 'waiting',
                'Extra' => '',
            ],
            'schedule' => [
                'Type' => 'varchar(255)',
                'Null' => 'YES',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'registered_at' => [
                'Type' => 'datetime',
                'Null' => 'NO',
                'Key' => '',
                'Default' => 'current_timestamp()',
                'Extra' => '',
            ],
            'revised_at' => [
                'Type' => 'datetime',
                'Null' => 'NO',
                'Key' => '',
                'Default' => 'current_timestamp()',
                'Extra' => '',
            ],
            'started_at' => [
                'Type' => 'datetime',
                'Null' => 'YES',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'finished_at' => [
                'Type' => 'datetime',
                'Null' => 'YES',
                'Key' => '',
                'Default' => NULL,
                'Extra' => '',
            ],
            'deleted_at' => [
                'Type' => 'datetime',
                'Null' => 'NO',
                'Key' => '',
                'Default' => '9999-12-31 23:59:59',
                'Extra' => '',
            ],
        ];

        $this->db->execute_query(
            "DESCRIBE `$this->table`",
            function ($stmt) use ($validate, $schema) {
                $fields = $stmt->fetchAll(PDO::FETCH_UNIQUE);
                foreach ($fields as &$row) {
                    foreach (array_keys($row) as $key) {
                        if (is_int($key)) {
                            unset($row[$key]);
                        }
                    }
                }
                if ($fields != $schema) {
                    $this->log->debug('incorrect column description', [
                        'expected' => var_export($schema, true),
                        'actual' => var_export($fields, true),
                    ]);
                }
                $validate($fields == $schema, 'incorrect column description');
            },
            true,
        );

        $this->db->execute_query(
            "SHOW INDEX FROM `$this->table` WHERE `Key_name` = 'uniqueness'",
            function ($stmt) use ($validate) {
                $stmt->execute();
                $fields = $stmt->fetchAll(PDO::FETCH_COLUMN, 4);
                $validate($fields == [
                    'site',
                    'hook',
                    'hook_instance',
                    'args_digest',
                    'deleted_at',
                ], 'incorrect "uniqueness" index');
            },
            true,
        );

        $this->db->execute_query(
            "SHOW INDEX FROM `$this->table` WHERE `Key_name` = 'status'",
            function ($stmt) use ($validate) {
                $stmt->execute();
                $fields = $stmt->fetchAll(PDO::FETCH_COLUMN, 4);
                $validate($fields == ['status', 'deleted_at'], 'incorrect "status" index');
            },
            true,
        );

        $this->db->execute_query(
            "SHOW INDEX FROM `$this->table` WHERE `Key_name` = 'site'",
            function ($stmt) use ($validate) {
                $stmt->execute();
                $fields = $stmt->fetchAll(PDO::FETCH_COLUMN, 4);
                $validate($fields == ['site', 'deleted_at'], 'incorrect "site" index');
            },
            true,
        );

        $this->db->execute_query(
            "SHOW INDEX FROM `$this->table` WHERE `Key_name` = 'hook'",
            function ($stmt) use ($validate) {
                $stmt->execute();
                $fields = $stmt->fetchAll(PDO::FETCH_COLUMN, 4);
                $validate($fields == ['hook', 'deleted_at'], 'incorrect "hook" index');
            },
            true,
        );

        $this->db->execute_query(
            "SHOW INDEX FROM `$this->table` WHERE `Key_name` = 'status-finished_at'",
            function ($stmt) use ($validate) {
                $stmt->execute();
                $fields = $stmt->fetchAll(PDO::FETCH_COLUMN, 4);
                $validate(
                    $fields == ['status', 'finished_at'],
                    'incorrect "status-finished_at" index'
                );
            },
            true,
        );
    }

    public function cleanup()
    {
        $expired = new DateTime('now', new DateTimeZone('UTC'));
        $expired->sub(new DateInterval("PT{$this->cleanup_delay}S"));
        $expired_str = $expired->format(MYSQL_DATE_FORMAT);

        // $this->log->debug('cleaning up', ['expired' => $expired_str]);

        try {
            $this->db->prepare_query(
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

                    $this->log->debug('db cleaned up', [
                        'deleted_rows' => $count,
                        'expired' => $expired_str,
                    ]);
                },
                true,
            );
        } catch (Exception $e) {
            $this->log->error('cleanup failed', ['ex_message' => $e->getMessage()]);
            throw $e;
        }

        // $this->log->debug('cleanup done', ['expired' => $expired_str]);
    }

    public function cleanup_abandoned()
    {
        $this->log->debug('cleaning up abandoned');

        $this->db->prepare_query(
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

                    $this->log->error('abandoned worker found', $job->log_values_full());
                    $this->log->error_app('abandoned worker found', $job->log_values_full());
                    $job->mark_waiting();
                }
            },
            true,
        );

        $this->log->debug('cleanup abandoned done');
    }

    public function is_maintenance_mode()
    {
        $in_maintenance = file_exists($this->maintenance_path);
        if ($in_maintenance) {
            $this->log->debug('is maintenance mode', ['file' => $this->maintenance_path]);
        }
        return $in_maintenance;
    }

    public function run()
    {
        pcntl_signal(SIGTERM, [$this, 'terminate_by_signal']);
        pcntl_signal(SIGINT, [$this, 'terminate_by_signal']);
        pcntl_signal(SIGQUIT, [$this, 'terminate_by_signal']);

        $this->hooks->run('Runner.run.before');

        $prev_ip_check = $prev_cleanup = time();
        try {
            while (true) {
                pcntl_signal_dispatch();
                $this->hooks->run('Runner.run.loop_start', $this);

                $now = time();

                if ($this->ip_check_interval <= $now - $prev_ip_check) {
                    $prev_ip_check = $now;
                    if (!in_array($this->eip, ($this->get_current_ips)())) {
                        $this->log->info('eip lost during excecution, exiting...');
                        $this->terminate('eip');
                        break;
                    }
                }

                if ($this->is_maintenance_mode()) {
                    $this->log->info('maintenance mode activated during excecution, exiting...');
                    $this->terminate('maintenance');
                    break;
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
        } catch (SignalInterrupt $e) {
            throw $e;
        } catch (Exception $e) {
            $this->terminate_by_exception($e);
        }
    }

    protected function terminate_by_signal($signal)
    {
        $this->log->info(
            sprintf('cavalcade received terminate signal during excecution (%s), exiting...', $signal)
        );
        $this->terminate($signal);

        throw new SignalInterrupt('Terminated by signal', $signal);
    }

    protected function terminate_by_exception($e)
    {
        $this->log->info(sprintf('exception occurred during execution (%s), exiting...', $e->getMessage()));
        $this->terminate(get_class($e));

        throw $e;
    }

    protected function terminate($type)
    {
        $this->hooks->run('Runner.terminate.will_terminate', $type);

        $this->log->debug(sprintf('shutting down %d worker(s)...', count($this->workers)));

        // Wait and clean up
        while (!empty($this->workers)) {
            $this->check_workers();
            usleep(100000);
        }

        $this->hooks->run('Runner.terminate.terminated', $type);

        unset($this->db);
    }

    public function get_wp_path()
    {
        return $this->wp_path;
    }

    protected function get_next_job()
    {
        // $this->log->debug('trying to get next job');

        try {
            $res = $this->db->prepare_query(
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
                true,
            );
        } catch (Exception $e) {
            $this->log->error('failed to get next job', ['ex_message' => $e->getMessage()]);
            throw $e;
        }

        if (empty($res)) {
            // $this->log->debug('next job not found');
        } else {
            $this->log->debug('next job', $res->log_values());
        }

        return $res;
    }

    protected function run_job($job)
    {
        try {
            $this->hooks->run('Runner.run_job.acquiring_lock', $this->db->get_connection(), $job);
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
            $this->log->debug_app('preparing for worker', ['job_id' => $job->id, 'command' => $command]);

            $spec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];
            $process = proc_open($command, $spec, $pipes, $this->wp_path);

            if ($process === false) {
                throw new Exception('unable to proc_open()');
            }

            // Disable blocking to allow partial stream reads before EOF.
            if (!stream_set_blocking($pipes[1], false) || !stream_set_blocking($pipes[2], false)) {
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                throw new Exception('failed to set stdout to non-blocking');
            }
        } catch (Exception $e) {
            $this->log->error('exception during starting job', [
                'ex_message' => $e->getMessage(),
            ]);
            try {
                $this->hooks->run('Runner.run_job.canceling_lock', $this->db->get_connection(), $job);
                $job->cancel_lock();
            } catch (Exception $e) {
                $this->log->error('failed to cancel lock', ['ex_message' => $e->getMessage()]);
                throw $e;
            }
            sleep(10); // throttle
            return;
        }
        $worker = new Worker($process, $pipes, $job, $this->log, $error_log_file, $this->max_log_size);
        $this->workers[] = $worker;

        $this->log->debug('worker started', $job->log_values());
        $this->hooks->run('Runner.run_job.started', $worker, $job);
    }

    protected function job_command($job, $error_log_file)
    {
        $siteurl = $job->get_site_url();

        $command = "php -d error_log=$error_log_file $this->wpcli_path --no-color cavalcade run $job->id";

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

        // $this->log->debug('checking workers', ['count' => count($this->workers)]);

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
            // $this->log->debug('changes not found', ['count' => count($this->workers)]);
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
                $this->hooks->run('Runner.check_workers.job_finishing', $this->db->get_connection(), $worker, $worker->job);
                if ($worker->shutdown()) {
                    $worker->job->mark_done();
                    $this->log->info_app('job completed', $worker->log_values_full());
                    $this->hooks->run('Runner.check_workers.job_completed', $worker, $worker->job);
                } else {
                    $worker->job->mark_done();
                    $this->log->error_app(
                        'job failed: failed to shutdown worker',
                        $worker->log_values_full()
                    );
                    $this->hooks->run('Runner.check_workers.job_failed', $worker, $worker->job);
                }
            } catch (Exception $e) {
                $this->log->error('failed to finish job properly', [
                    'ex_message' => $e->getMessage(),
                ]);
                throw $e;
            } finally {
                unset($this->workers[$id]);
            }
        }
    }
}
