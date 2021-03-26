<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_Abandoned_Jobs extends CavalcadeRunner_TestCase
{
    protected function beforeRunnerStarts()
    {
        wp_schedule_single_event(time(), JOB, [__FUNCTION__]);
        wp_schedule_event(time(), RECUR_HOURLY, JOB2, [__FUNCTION__]);
        $this->set_running(JOB);
        $this->set_running(JOB2);
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
        sleep(2);

        $job = $this->get_job(JOB);
        $this->assertEquals('done', $job->status);
        $this->assertNotNull($job->finished_at);

        $job2 = $this->get_job(JOB2);
        $this->assertEquals('waiting', $job2->status);
        $this->assertNotNull($job2->finished_at);

        sleep(2);

        $log = file_get_contents(RUNNER_LOG);
        $this->assertEquals(2, substr_count($log, 'ERROR'));
        $this->assertEquals(2, substr_count($log, 'abandoned worker found'));
        $this->assertEquals(1, substr_count($log, 'Cavalcade Runner started'));
    }
}
