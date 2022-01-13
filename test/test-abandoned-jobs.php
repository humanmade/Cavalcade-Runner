<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_Abandoned_Jobs extends CavalcadeRunner_TestCase
{
    private static function go_wpcli_blocking()
    {
        file_put_contents(WPTEST_WPCLI_FIFO, "\n");
    }

    private static function wait_wpcli_blocking()
    {
        file_get_contents(WPCLI_WPTEST_FIFO);
    }

    protected function start_runner()
    {
        # Don't start runner at this time.
    }

    private function set_running($job)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE `$this->table` SET `status` = 'running', `started_at` = NOW() WHERE `hook` = %s",
            [$job],
        ));
    }

    function test_abandoned_single_job()
    {
        wp_schedule_single_event(time(), JOB, [__FUNCTION__]);
        $this->set_running(JOB);

        # Start runner after adding jobs.
        $this->start_runner_process();
        $this->open_gate();

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(10);

        $log = file_get_contents(RUNNER_LOG);
        $this->assertEquals(0, substr_count($log, 'ERROR'));
        $this->assertEquals(1, substr_count($log, 'abandoned worker found'));
        $this->assertEquals(1, substr_count($log, 'marked as waiting'));
        $this->assertEquals(1, substr_count($log, 'job completed'));
    }

    function test_abandoned_schedule_job()
    {
        wp_schedule_event(time(), RECUR_HOURLY, JOB2, [__FUNCTION__]);
        $this->set_running(JOB2);

        # Start runner after adding jobs.
        $this->start_runner_process();
        $this->open_gate();

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(10);

        $log = file_get_contents(RUNNER_LOG);
        $this->assertEquals(0, substr_count($log, 'ERROR'));
        $this->assertEquals(1, substr_count($log, 'abandoned worker found'));
        $this->assertEquals(1, substr_count($log, 'marked as waiting'));
        $this->assertEquals(1, substr_count($log, 'job completed'));
    }
}
