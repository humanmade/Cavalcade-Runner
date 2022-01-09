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
        wp_schedule_single_event(time(), 'test_job_acquiring_lock_error');

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
        wp_schedule_single_event(time(), 'test_job_canceling_lock_error');

        sleep(10);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_01000_message = false;
        $exception_for_testing_message = false;
        $failed_to_cancel_lock_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"') && strstr($line, '"01000"')) {
                $database_error_01000_message = true;
            }

            if (strstr($line, '"failed to cancel lock"')) {
                $failed_to_cancel_lock_message = true;
            }

            if (strstr($line, '"exception for testing"')) {
                $exception_for_testing_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertTrue($database_error_01000_message);
        $this->assertTrue($exception_for_testing_message);
        $this->assertTrue($failed_to_cancel_lock_message);
        $this->assertTrue($fatal_error);
    }

    function test_job_finishing_error()
    {
        wp_schedule_single_event(time(), 'test_job_finishing_error');

        self::wait_wpcli_blocking();
        self::go_wpcli_blocking();

        sleep(10);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_01000_message = false;
        $failed_to_finish_job_properly_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"') && strstr($line, '"01000"')) {
                $database_error_01000_message = true;
            }

            if (strstr($line, '"failed to finish job properly"')) {
                $failed_to_finish_job_properly_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertTrue($database_error_01000_message);
        $this->assertTrue($failed_to_finish_job_properly_message);
        $this->assertTrue($fatal_error);
    }

    function test_lost_connection_error()
    {
        wp_schedule_single_event(time(), 'test_lost_connection_error');

        sleep(20);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_message = false;
        $worker_started_message = false;
        $retriable_database_error_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"')) {
                $database_error_message = true;
            }

            if (strstr($line, '"retriable database error"')) {
                $retriable_database_error_message = true;
            }

            if (strstr($line, '"worker started"')) {
                $worker_started_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertFalse($database_error_message);
        $this->assertTrue($retriable_database_error_message);
        $this->assertTrue($worker_started_message);
        $this->assertFalse($fatal_error);
    }

    function test_packet_out_of_order_error()
    {
        wp_schedule_single_event(time(), 'test_packet_out_of_order_error');

        sleep(20);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_message = false;
        $worker_started_message = false;
        $retriable_database_error_message = false;
        $exception_during_starting_job_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"')) {
                $database_error_message = true;
            }

            if (strstr($line, '"retriable database error"')) {
                $retriable_database_error_message = true;
            }

            if (strstr($line, '"worker started"')) {
                $worker_started_message = true;
            }

            if (strstr($line, '"exception during starting job"')) {
                $exception_during_starting_job_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertFalse($database_error_message);
        $this->assertTrue($retriable_database_error_message);
        $this->assertTrue($worker_started_message);
        $this->assertFalse($exception_during_starting_job_message);
        $this->assertFalse($fatal_error);
    }

    function test_repeating_packet_out_of_order_error()
    {
        wp_schedule_single_event(time(), 'test_repeating_packet_out_of_order_error');

        sleep(30);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $retriable_database_error_message = false;
        $exception_during_starting_job_message = false;
        $failed_to_cancel_lock_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"retriable database error"')) {
                $retriable_database_error_message = true;
            }

            if (strstr($line, '"exception during starting job"')) {
                $exception_during_starting_job_message = true;
            }

            if (strstr($line, '"failed to cancel lock"')) {
                $failed_to_cancel_lock_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertTrue($retriable_database_error_message);
        $this->assertTrue($exception_during_starting_job_message);
        $this->assertTrue($failed_to_cancel_lock_message);
        $this->assertTrue($fatal_error);
    }

    function test_unknown_php_error()
    {
        wp_schedule_single_event(time(), 'test_unknown_php_error');

        sleep(20);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_message = false;
        $worker_started_message = false;
        $retriable_database_error_message = false;
        $exception_during_starting_job_message = false;
        $failed_to_cancel_lock_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"')) {
                $database_error_message = true;
            }

            if (strstr($line, '"retriable database error"')) {
                $retriable_database_error_message = true;
            }

            if (strstr($line, '"worker started"')) {
                $worker_started_message = true;
            }

            if (strstr($line, '"exception during starting job"')) {
                $exception_during_starting_job_message = true;
            }

            if (strstr($line, '"failed to cancel lock"')) {
                $failed_to_cancel_lock_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertFalse($database_error_message);
        $this->assertFalse($retriable_database_error_message);
        $this->assertFalse($worker_started_message);
        $this->assertTrue($exception_during_starting_job_message);
        $this->assertFalse($failed_to_cancel_lock_message);
        $this->assertFalse($fatal_error);
    }

    function test_repeating_unknown_php_error()
    {
        wp_schedule_single_event(time(), 'test_repeating_unknown_php_error');

        sleep(20);

        $lines = explode("\n", file_get_contents(RUNNER_LOG));
        $database_error_message = false;
        $worker_started_message = false;
        $retriable_database_error_message = false;
        $exception_during_starting_job_message = false;
        $failed_to_cancel_lock_message = false;
        $fatal_error = false;
        foreach ($lines as $line) {
            if (strstr($line, '"database error"')) {
                $database_error_message = true;
            }

            if (strstr($line, '"retriable database error"')) {
                $retriable_database_error_message = true;
            }

            if (strstr($line, '"worker started"')) {
                $worker_started_message = true;
            }

            if (strstr($line, '"exception during starting job"')) {
                $exception_during_starting_job_message = true;
            }

            if (strstr($line, '"failed to cancel lock"')) {
                $failed_to_cancel_lock_message = true;
            }

            if (strstr($line, '"FATAL"')) {
                $fatal_error = true;
            }
        }
        $this->assertFalse($database_error_message);
        $this->assertFalse($retriable_database_error_message);
        $this->assertFalse($worker_started_message);
        $this->assertTrue($exception_during_starting_job_message);
        $this->assertTrue($failed_to_cancel_lock_message);
        $this->assertTrue($fatal_error);
    }
}
