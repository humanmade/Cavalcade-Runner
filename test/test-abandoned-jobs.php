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

    public function setUp()
    {
        parent::setUp();

        wp_schedule_single_event(time(), JOB, [__FUNCTION__]);
        wp_schedule_event(time(), RECUR_HOURLY, JOB2, [__FUNCTION__]);
        $this->set_running(JOB);
        $this->set_running(JOB2);

        # Start runner after adding jobs.
        $this->start_runner_process();
        $this->open_gate();
    }

    protected function start_runner()
    {
        # Don't start runner at this time.
    }

    private function get_job($job)
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM `$this->table` WHERE `hook` = %s",
            [$job],
        );

        $res = $wpdb->get_results($sql);

        return 0 < count($res) ? $res[0] : null;
    }

    private function set_running($job)
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE `$this->table` SET `status` = 'running', `started_at` = NOW() WHERE `hook` = %s",
            [$job],
        ));
    }

    function test_abandoned_single_jobs()
    {
        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(10);

        $log = file_get_contents(RUNNER_LOG);
        $this->assertEquals(4, substr_count($log, 'ERROR'));
        $this->assertEquals(4, substr_count($log, 'abandoned worker found'));
        $this->assertEquals(2, substr_count($log, 'marked as waiting'));
        $this->assertEquals(2, substr_count($log, 'job completed'));
    }
}
