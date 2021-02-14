<?php

namespace HM\Cavalcade\Runner\Tests;

use DateTime;

const JOB = 'test_job';
const FAILED_JOB = 'failed_test_job';
const WPTEST_WPCLI_FIFO = 'work/wptest-wpcli.fifo';
const WPCLI_WPTEST_FIFO = 'work/wpcli-wptest.fifo';

class Test_Job extends CavalcadeRunner_TestCase
{
    function setUp()
    {
        parent::setUp();

        @unlink(WPTEST_WPCLI_FIFO);
        @unlink(WPCLI_WPTEST_FIFO);
        posix_mkfifo(WPTEST_WPCLI_FIFO, 0644);
        posix_mkfifo(WPCLI_WPTEST_FIFO, 0644);
    }

    private static function go_wpcli_blocking()
    {
        file_put_contents(WPTEST_WPCLI_FIFO, "\n");
    }

    private static function wait_wpcli_blocking()
    {
        file_get_contents(WPCLI_WPTEST_FIFO);
    }

    private static function get_job($job)
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->base_prefix}cavalcade_jobs WHERE hook = %s",
            [$job],
        );

        $res = $wpdb->get_results($sql)[0];

        // echo var_export($res, true);

        return $res;
    }

    private static function nextrun($job)
    {
        return mysql2date('G', $job->nextrun);
    }

    function test_single_event()
    {
        wp_schedule_single_event(time(), JOB, [__FUNCTION__]);
        $this->assertEquals(STATUS_WAITING, self::get_job(JOB)->status);

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();
        sleep(2);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));
        $this->assertEquals(STATUS_RUNNING, file_get_contents(ACTUAL_STATUS));

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);

        $nextrun = self::nextrun($job);
        $this->assertTrue(strtotime('-1 minutes') < $nextrun);
        $this->assertTrue($nextrun <= time());
    }

    function test_schedule_event()
    {
        wp_schedule_event(time(), RECUR_HOURLY, JOB, [__FUNCTION__]);
        $this->assertEquals(STATUS_WAITING, self::get_job(JOB)->status);

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();
        sleep(2);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));
        $this->assertEquals(STATUS_RUNNING, file_get_contents(ACTUAL_STATUS));

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);

        $nextrun = self::nextrun($job);
        $this->assertTrue(strtotime('+59 minutes') < $nextrun);
        $this->assertTrue($nextrun < strtotime('+61 minutes'));
    }

    function test_unschedule_immediately()
    {
        $ts = time();
        wp_schedule_single_event($ts, JOB, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        self::get_job(JOB);
        $unscheduled = wp_unschedule_event($ts, JOB, [__FUNCTION__]);
        $this->assertTrue($unscheduled);

        self::go_wpcli_blocking();

        sleep(2);

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);

        $nextrun = self::nextrun($job);
        $this->assertTrue(strtotime('-1 minutes') < $nextrun);
        $this->assertTrue($nextrun <= time());
    }

    function test_clear_schedule_immediately()
    {
        wp_schedule_single_event(time(), JOB, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        $hook_unscheduled = wp_clear_scheduled_hook(JOB, [__FUNCTION__]);
        $this->assertSame(1, $hook_unscheduled);

        self::go_wpcli_blocking();

        $this->markTestIncomplete('Invalid Job ID occurs');

        sleep(2);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));
        $this->assertEquals(STATUS_COMPLETED, file_get_contents(ACTUAL_STATUS));
    }

    function test_failed_event()
    {
        wp_schedule_single_event(time(), FAILED_JOB, [__FUNCTION__]);
        $this->assertEquals(STATUS_WAITING, self::get_job(FAILED_JOB)->status);

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();
        sleep(2);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));
        $this->assertEquals(STATUS_RUNNING, file_get_contents(ACTUAL_STATUS));

        $job = self::get_job(FAILED_JOB);
        $this->assertEquals(STATUS_FAILED, $job->status);

        $nextrun = self::nextrun($job);
        $this->assertTrue(strtotime('-1 minutes') < $nextrun);
        $this->assertTrue($nextrun <= time());
    }
}
