<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_Job extends CavalcadeRunner_TestCase
{
    const DATE_FORMAT = 'Y-m-d H:i:s';

    private static function go_wpcli_blocking()
    {
        file_put_contents(WPTEST_WPCLI_FIFO, "\n");
    }

    private static function wait_wpcli_blocking()
    {
        file_get_contents(WPCLI_WPTEST_FIFO);
    }

    private function get_job($job)
    {
        global $wpdb;

        $sql = $wpdb->prepare("SELECT * FROM `$this->table` WHERE `hook` = %s", [$job]);
        $res = $wpdb->get_results($sql);

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
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertNull($job->started_at);
        $this->assertNull($job->finished_at);

        self::wait_wpcli_blocking();

        $in_time = time();
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_RUNNING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertNull($job->finished_at);

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_DONE, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertEquals(EMPTY_DELETED_AT, $job->deleted_at);

        $this->assertBetweenWeak(
            strtotime('-1 minutes'),
            time(),
            self::as_epoch($job->nextrun),
        );

        sleep(3);

        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_DONE, $job->status);

        sleep(6);

        $this->assertNull($this->get_job(JOB));
    }

    function test_deleted_event()
    {
        global $wpdb;

        $pre_time = time();
        wp_schedule_single_event($pre_time, JOB, [__FUNCTION__]);
        $job = $this->get_job(JOB);
        $wpdb->query("UPDATE `$this->table` SET `deleted_at` = '2000-01-01 00:00:00' WHERE `id` = $job->id");

        sleep(5);

        $this->assertFileDoesNotExist(ACTUAL_FUNCTION);
        $this->assertNull($this->get_job(JOB));
    }

    function test_schedule_event()
    {
        $pre_time = time();
        wp_schedule_event($pre_time, RECUR_HOURLY, JOB, [__FUNCTION__]);
        $this->assertEquals(STATUS_WAITING, $this->get_job(JOB)->status);

        self::wait_wpcli_blocking();

        $in_time = time();
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_RUNNING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertNull($job->finished_at);

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertEquals(EMPTY_DELETED_AT, $job->deleted_at);

        $this->assertBetweenWeak(
            strtotime('+59 minutes'),
            strtotime('+61 minutes'),
            self::as_epoch($job->nextrun),
        );

        sleep(9);

        $job = $this->get_job(JOB);
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
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_DONE, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->deleted_at));
        $this->assertBetweenWeak(strtotime('-1 minutes'), time(), self::as_epoch($job->nextrun));

        sleep(3);

        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_DONE, $job->status);

        sleep(6);

        $this->assertNull($this->get_job(JOB));
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
        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->deleted_at));

        sleep(3);

        $job = $this->get_job(JOB);
        $this->assertEquals(STATUS_WAITING, $job->status);

        sleep(6);

        $this->assertNull($this->get_job(JOB));
    }

    function test_failed_event()
    {
        $pre_time = time();
        wp_schedule_single_event($pre_time, JOB_FAILED, [__FUNCTION__]);

        self::wait_wpcli_blocking();

        $in_time = time();

        self::go_wpcli_blocking();
        sleep(3);

        $this->assertEquals(__FUNCTION__, file_get_contents(ACTUAL_FUNCTION));

        $post_time = time();
        $job = $this->get_job(JOB_FAILED);
        $this->assertEquals(STATUS_DONE, $job->status);
        $this->assertBetweenWeak($pre_time, $in_time, self::as_epoch($job->started_at));
        $this->assertBetweenWeak($in_time, $post_time, self::as_epoch($job->finished_at));
        $this->assertEquals(EMPTY_DELETED_AT, $job->deleted_at);
        $this->assertBetweenWeak(strtotime('-1 minutes'), time(), self::as_epoch($job->nextrun));

        sleep(3);

        $job = $this->get_job(JOB_FAILED);
        $this->assertEquals(STATUS_DONE, $job->status);

        sleep(6);

        $this->assertNull($this->get_job(JOB_FAILED));
    }

    function test_chatty_event()
    {
        wp_schedule_single_event(time(), JOB_CHATTY);

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(3);

        $log_lines = explode("\n", file_get_contents(RUNNER_LOG));

        foreach ($log_lines as $line) {
            if (strstr($line, '"job completed"') !== false) {
                $log = json_decode($line);
                $this->assertEquals(MAX_LOG_SIZE, strlen($log->stdout));
                $this->assertEquals(MAX_LOG_SIZE, strlen($log->stderr));
                $this->assertEquals(MAX_LOG_SIZE, strlen($log->error_log));
                return;
            }
        }
        $this->fail();
    }

    function test_maintenance_mode()
    {
        wp_schedule_single_event(time(), JOB_LONG, [__FUNCTION__]);

        sleep(2);

        self::wait_wpcli_blocking();

        file_put_contents(DOT_MAINTENANCE, '<?php ');
        sleep(2);

        self::go_wpcli_blocking();

        sleep(10 + 2);

        $this->assertTrue(self::log_exists('maintenance mode activated'));
        $job = $this->get_job(JOB_LONG);
        $this->assertEquals(STATUS_DONE, $job->status);
    }

    function test_sigterm()
    {
        wp_schedule_single_event(time(), JOB_LONG, [__FUNCTION__]);

        sleep(2);

        self::wait_wpcli_blocking();

        file_put_contents(RUNNER_CTRL_FIFO, "sigterm\n");
        file_get_contents(RUNNER_CTRL_DONE_FIFO);

        self::go_wpcli_blocking();

        sleep(10 + 2);

        $this->assertTrue(self::log_exists('"shutting down"'));
        $this->assertTrue(self::log_exists('"signal interrupt"'));
        $job = $this->get_job(JOB_LONG);
        $this->assertEquals(STATUS_DONE, $job->status);
    }
}
