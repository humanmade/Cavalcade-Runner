<?php

namespace HM\Cavalcade\Runner\Tests;

use DateTime;
use Exception;
use WP_UnitTestCase;

const SCHEMA_VERSION = 12;
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
const STATE_FILE = '/workspace/work/runner-state.json';
const RUNNER_CTRL_FIFO = '/workspace/work/runner_ctrl.fifo';
const RUNNER_CTRL_DONE_FIFO = '/workspace/work/runner_ctrl_done.fifo';
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

    protected function open_gate()
    {
        # Let Cavalcade-Runner start processing cron jobs by closing lock and attach EIP.
        fclose($this->lockfile);
        file_put_contents(PUBLIC_IP, EIP);

        self::wait_runner_blocking();
    }

    protected function start_runner_process()
    {
        # Start Cavalcade-Runner process.
        file_put_contents(RUNNER_CTRL_FIFO, "start\n");
        file_get_contents(RUNNER_CTRL_DONE_FIFO);
    }

    function setUp()
    {
        global $wpdb;

        # Exit Cavalcade-Runner process and confirm it.
        file_put_contents(RUNNER_CTRL_FIFO, "exit\n");
        file_get_contents(RUNNER_CTRL_DONE_FIFO);

        # This will delete files opened by the runner process.
        $this->close_communication_channels();

        $this->table = $wpdb->base_prefix . 'cavalcade_jobs';

        # Recreate database.
        # Database is required to run WordPress with Cavalcade plugin.
        $wpdb->query("DROP TABLE IF EXISTS `$this->table`");
        file_put_contents(RUNNER_CTRL_FIFO, "create_table\n");
        file_get_contents(RUNNER_CTRL_DONE_FIFO);

        # Install WordPress.
        $ms_tests = getenv('WP_MULTISITE') === '1' ? 'run_ms_tests' : 'no_ms_tests';
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

        # Clean up default jobs added by install task.
        _set_cron_array([]);
        $wpdb->query("TRUNCATE `$this->table`");

        # Create fifo used to communicate to Cavalcade jobs.
        posix_mkfifo(WPTEST_WPCLI_FIFO, 0644);
        posix_mkfifo(WPCLI_WPTEST_FIFO, 0644);
        posix_mkfifo(WPTEST_RUNNER_FIFO, 0644);
        posix_mkfifo(RUNNER_WPTEST_FIFO, 0644);

        # Remove files created by previous test.
        @unlink(LOCKFILE);
        @unlink(STATE_FILE);
        @unlink(ACTUAL_FUNCTION);
        @unlink(RUNNER_STARTED);
        @unlink(RUNNER_LOG);
        @unlink(DOT_MAINTENANCE);

        # Create state json.
        file_put_contents(STATE_FILE, sprintf('{"schema_version":%d}', SCHEMA_VERSION));
        # Lock used by Cavalcade-Runner is activated by default,
        # which means Cavalcade-Runner cannot process cron jobs at this time.
        $this->lockfile = fopen(LOCKFILE, 'w+');
        flock($this->lockfile, LOCK_EX);
        # Ephemeral public IP attached to the mock VM also means
        # Cavalcade-Runner is unable process cron jobs.
        file_put_contents(PUBLIC_IP, EPHEMERAL_IP);

        $this->start_runner();
    }

    protected function start_runner()
    {
        $this->start_runner_process();
        $this->open_gate();
    }

    private function close_communication_channels()
    {
        # Close files used for communication.
        @fclose($this->lockfile);
        @unlink(WPTEST_WPCLI_FIFO);
        @unlink(WPCLI_WPTEST_FIFO);
        @unlink(WPTEST_RUNNER_FIFO);
        @unlink(RUNNER_WPTEST_FIFO);
    }

    function tearDown()
    {
        $this->close_communication_channels();
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
