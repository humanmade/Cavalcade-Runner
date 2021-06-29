<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_Metadata_Error extends CavalcadeRunner_TestCase
{
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

    function test_metadata_error()
    {
        wp_schedule_single_event(time(), JOB_LONG, [__FUNCTION__]);

        sleep(2);

        $this->assertFalse(self::log_exists('"shutting down"'));

        self::wait_wpcli_blocking();

        file_put_contents(GET_CURRENT_IPS_ERROR, '');
        sleep(2);

        self::go_wpcli_blocking();

        sleep(10 + 2);

        $this->assertTrue(self::log_exists('"shutting down"'));
        $this->assertTrue(self::log_exists('"metadata error"'));
        $job = $this->get_job(JOB_LONG);
        $this->assertEquals(STATUS_DONE, $job->status);
    }

    function tearDown()
    {
        @unlink(GET_CURRENT_IPS_ERROR);
        parent::tearDown();
    }
}
