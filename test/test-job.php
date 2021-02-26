<?php

namespace HM\Cavalcade\Runner\Tests;

const JOB = 'test_job';
const FAILED_JOB = 'failed_test_job';

class Test_Job extends CavalcadeRunner_TestCase
{
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

        $res = $wpdb->get_results($sql);

        // echo var_export($res, true);

        return 0 < count($res) ? $res[0] : null;
    }

    private static function as_epoch($mysql_time)
    {
        return mysql2date('G', $mysql_time);
    }

    function test_single_event()
    {
        $pre_time = time();
        wp_schedule_single_event($pre_time, JOB, [__FUNCTION__]);
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertNull($job->started_at);
        $this->assertNull($job->finished_at);

        self::wait_wpcli_blocking();

        $in_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_RUNNING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertNull($job->finished_at);

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertNull($job->deleted_at);

        $this->assertBetweenWeak(
            strtotime('-1 minutes'),
            time(),
            self::as_epoch($job->nextrun),
        );

        sleep(3);

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);

        sleep(6);

        $this->assertNull(self::get_job(JOB));
    }

    function test_schedule_event()
    {
        $pre_time = time();
        wp_schedule_event($pre_time, RECUR_HOURLY, JOB, [__FUNCTION__]);
        $this->assertEquals(STATUS_WAITING, self::get_job(JOB)->status);

        self::wait_wpcli_blocking();

        $in_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_RUNNING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertNull($job->finished_at);

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertNull($job->deleted_at);

        $this->assertBetweenWeak(
            strtotime('+59 minutes'),
            strtotime('+61 minutes'),
            self::as_epoch($job->nextrun),
        );

        sleep(9);

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
    }

    function test_unschedule_immediately()
    {
        $pre_time = time();
        wp_schedule_single_event($pre_time, JOB, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        $in_time = time();
        $unscheduled = wp_unschedule_event($pre_time, JOB, [__FUNCTION__]);
        $this->assertTrue($unscheduled);

        self::go_wpcli_blocking();
        sleep(3);

        $post_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->deleted_at));
        $this->assertBetweenWeak(strtotime('-1 minutes'), time(), self::as_epoch($job->nextrun));

        sleep(3);

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_COMPLETED, $job->status);

        sleep(6);

        $this->assertNull(self::get_job(JOB));
    }

    function test_clear_schedule_immediately()
    {
        $pre_time = time();
        wp_schedule_event($pre_time, RECUR_HOURLY, JOB, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        $in_time = time();
        $hook_unscheduled = wp_clear_scheduled_hook(JOB, [__FUNCTION__]);
        $this->assertSame(1, $hook_unscheduled);

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->deleted_at));

        sleep(3);

        $job = self::get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);

        sleep(6);

        $this->assertNull(self::get_job(JOB));
    }

    function test_failed_event()
    {
        $pre_time = time();
        wp_schedule_single_event($pre_time, FAILED_JOB, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        $in_time = time();

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = self::get_job(FAILED_JOB);
        $this->assertEquals(STATUS_FAILED, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertNull($job->deleted_at);
        $this->assertBetweenWeak(strtotime('-1 minutes'), time(), self::as_epoch($job->nextrun));

        sleep(3);

        $job = self::get_job(FAILED_JOB);
        $this->assertEquals(STATUS_FAILED, $job->status);

        sleep(6);

        $this->assertNull(self::get_job(FAILED_JOB));
    }
}
