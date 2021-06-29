<?php

namespace HM\Cavalcade\Runner\Tests;

use DateTime;
use Exception;
use WP_UnitTestCase;

const RUNNER_LOG = '/workspace/work/cavalcade-runner.log';
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
const DOT_MAINTENANCE = '/www-work/.maintenance';
const PUBLIC_IP = '/workspace/work/public-ip';
const GET_CURRENT_IPS_ERROR = '/workspace/work/get-current-ips-error';
const EPHEMERAL_IP = '192.0.2.1';
const EIP = '192.0.2.255';
const JOB = 'test_job';
const JOB2 = 'test_job2';
const JOB_CHATTY = 'test_job_chatty';
const JOB_FAILED = 'test_job_failed';
const JOB_LONG = 'test_job_long';
const JOB_ACQUIRING_LOCK_ERROR = 'test_job_acquiring_lock_error';
const JOB_CANCELING_LOCK_ERROR = 'test_job_canceling_lock_error';
const JOB_FINISHING_ERROR = 'test_job_finishing_error';
const EMPTY_DELETED_AT = '9999-12-31 23:59:59';
const MAX_LOG_SIZE = 2000;

abstract class CavalcadeRunner_TestCase extends WP_UnitTestCase
{
    protected $lockfile;
    protected $table;

    protected static function wait_runner_blocking()
    {
        file_get_contents(RUNNER_WPTEST_FIFO);
    }

    public static function log($message)
    {
        $time = (new DateTime())->format(DateTime::RFC3339_EXTENDED);
        echo "$time $message";
    }

    public static function log_exists($text)
    {
        $content = file_get_contents(RUNNER_LOG);
        return strpos($content, $text) !== false;
    }

    function setUp()
    {
        global $wpdb;

        $this->table = $wpdb->base_prefix . 'cavalcade_jobs';
        $wpdb->query("DROP TABLE IF EXISTS `$this->table`");

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

        $wpdb->query("TRUNCATE `$this->table`");

        posix_mkfifo(WPTEST_WPCLI_FIFO, 0644);
        posix_mkfifo(WPCLI_WPTEST_FIFO, 0644);
        posix_mkfifo(WPTEST_RUNNER_FIFO, 0644);
        posix_mkfifo(RUNNER_WPTEST_FIFO, 0644);

        file_put_contents(RESTART_SIG_FIFO, "\n");
        file_get_contents(STOPPED_SIG_FIFO);

        @unlink(LOCKFILE);
        @unlink(ACTUAL_FUNCTION);
        @unlink(RUNNER_STARTED);
        @unlink(RUNNER_LOG);
        @unlink(DOT_MAINTENANCE);

        $this->lockfile = fopen(LOCKFILE, 'w+');
        flock($this->lockfile, LOCK_EX);
        file_put_contents(PUBLIC_IP, EPHEMERAL_IP);

        $this->beforeRunnerStarts();

        file_put_contents(CLEANED_SIG_FIFO, "\n");

        $this->afterSetUp();
    }

    protected function beforeRunnerStarts()
    {
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

    public function assertBetweenWeak($less, $greater, $actual)
    {
        $this->assertGreaterThanOrEqual($less, $actual);
        $this->assertLessThanOrEqual($greater, $actual);
    }

    public function start_transaction()
    {
        // Never use transaction.
    }
}
