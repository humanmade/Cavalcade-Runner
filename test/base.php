<?php

namespace HM\Cavalcade\Runner\Tests;

use DateTime;
use Exception;
use WP_UnitTestCase;

const ACTUAL_FUNCTION = '/workspace/work/test_function_name.txt';
const RECUR_HOURLY = 'hourly';
const STATUS_WAITING = 'waiting';
const STATUS_RUNNING = 'running';
const STATUS_DONE = 'done';
const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';
const WPTEST_WPCLI_FIFO = '/workspace/work/wptest-wpcli.fifo';
const WPCLI_WPTEST_FIFO = '/workspace/work/wpcli-wptest.fifo';
const RUNNER_WPTEST_FIFO = '/workspace/work/runner-wptest.fifo';
const WPTEST_RUNNER_FIFO = '/workspace/work/wptest-runner.fifo';
const RUNNER_STARTED = '/workspace/work/runner-started';
const LOCKFILE = '/workspace/work/runner.lock';
const RESTART_SIG_FIFO = '/workspace/work/restart.fifo';
const STOPPED_SIG_FIFO = '/workspace/work/stopped.fifo';
const CLEANED_SIG_FIFO = '/workspace/work/cleaned.fifo';
const PUBLIC_IP = '/workspace/work/public-ip';
const EPHEMERAL_IP = '192.0.2.1';
const EIP = '192.0.2.255';

abstract class CavalcadeRunner_TestCase extends WP_UnitTestCase
{
    protected $lockfile;

    protected static function wait_runner_blocking()
    {
        file_get_contents(RUNNER_WPTEST_FIFO);
    }

    public static function log($message)
    {
        $time = (new DateTime())->format(DateTime::RFC3339_EXTENDED);
        echo "$time $message";
    }

    function setUp()
    {
        global $wpdb;

        $ms_tests = (getenv('WP_MULTISITE') === '1') ? 'run_ms_tests' : 'no_ms_tests';
        $output = $retval = null;
        exec(
            "php /wp-tests/includes/install.php /wp-tests/wp-tests-config.php $ms_tests",
            $output,
            $retval,
        );
        if (0 !== $retval) {
            throw new Exception();
        }

        parent::setUp();
        _set_cron_array(array());

        $wpdb->query("TRUNCATE {$wpdb->base_prefix}cavalcade_jobs");

        posix_mkfifo(WPTEST_WPCLI_FIFO, 0644);
        posix_mkfifo(WPCLI_WPTEST_FIFO, 0644);
        posix_mkfifo(WPTEST_RUNNER_FIFO, 0644);
        posix_mkfifo(RUNNER_WPTEST_FIFO, 0644);

        file_put_contents(RESTART_SIG_FIFO, "\n");
        file_get_contents(STOPPED_SIG_FIFO);

        @unlink(LOCKFILE);
        @unlink(ACTUAL_FUNCTION);
        @unlink(RUNNER_STARTED);

        $this->lockfile = fopen(LOCKFILE, 'w+');
        flock($this->lockfile, LOCK_EX);
        file_put_contents(PUBLIC_IP, EPHEMERAL_IP);

        file_put_contents(CLEANED_SIG_FIFO, "\n");

        $this->afterSetUp();
    }

    function afterSetUp()
    {
        fclose($this->lockfile);
        file_put_contents(PUBLIC_IP, EIP);
        self::wait_runner_blocking();
    }

    function tearDown()
    {
        @fclose($this->lockfile);
        @unlink(WPTEST_WPCLI_FIFO);
        @unlink(WPCLI_WPTEST_FIFO);
        @unlink(WPTEST_RUNNER_FIFO);
        @unlink(RUNNER_WPTEST_FIFO);

        parent::tearDown();
    }

    function assertBetweenWeak($before, $after, $time)
    {
        $this->assertGreaterThanOrEqual($before, $time);
        $this->assertLessThanOrEqual($after, $time);
    }

    public function start_transaction()
    {
        // Never use transaction.
    }
}
