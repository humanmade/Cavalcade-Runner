<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_DB_Error extends CavalcadeRunner_TestCase
{
    private static function go_wpcli_blocking()
    {
        file_put_contents(WPTEST_WPCLI_FIFO, "\n");
    }

    private static function wait_wpcli_blocking()
    {
        file_get_contents(WPCLI_WPTEST_FIFO);
    }

    function test_acquire_lock_error()
    {
        wp_schedule_single_event(time(), JOB_ACQUIRING_LOCK_ERROR);

        sleep(30);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $count = 0;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"') && strstr($line, '"01000"')) {
                $count++;
            }
        }
        // Cavalcade-Runner keeps running after error
        $this->assertBetweenWeak(2, 3, $count);
    }

    function test_cancel_lock_error()
    {
        wp_schedule_single_event(time(), JOB_CANCELING_LOCK_ERROR);

        sleep(30);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $db_cleaned_up_count = 0;
        $database_error_01000_message = false;
        $exception_for_testing_message = false;
        $failed_to_cancel_lock_message = false;
        foreach ($lines as $line) {
            if (strstr($line, '"db cleaned up"')) {
                $db_cleaned_up_count++;
            }

            if (strstr($line, '"database error"') && strstr($line, '"01000"')) {
                $database_error_01000_message = true;
            }

            if (strstr($line, '"failed to cancel lock"')) {
                $failed_to_cancel_lock_message = true;
            }

            if (strstr($line, '"exception for testing"')) {
                $exception_for_testing_message = true;
            }
        }
        $this->assertBetweenWeak(7, 12, $db_cleaned_up_count);
        $this->assertTrue($database_error_01000_message);
        $this->assertTrue($exception_for_testing_message);
        $this->assertTrue($failed_to_cancel_lock_message);
    }

    function test_job_finishing_error()
    {
        wp_schedule_single_event(time(), JOB_FINISHING_ERROR);

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(30);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $db_cleaned_up_count = 0;
        $database_error_01000_message = false;
        $failed_to_finish_job_properly_message = false;
        foreach ($lines as $line) {
            if (strstr($line, '"db cleaned up"')) {
                $db_cleaned_up_count++;
            }

            if (strstr($line, '"database error"') && strstr($line, '"01000"')) {
                $database_error_01000_message = true;
            }

            if (strstr($line, '"failed to finish job properly"')) {
                $failed_to_finish_job_properly_message = true;
            }
        }
        $this->assertBetweenWeak(7, 13, $db_cleaned_up_count);
        $this->assertTrue($database_error_01000_message);
        $this->assertTrue($failed_to_finish_job_properly_message);
    }
}
