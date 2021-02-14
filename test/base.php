<?php

namespace HM\Cavalcade\Runner\Tests;

use WP_UnitTestCase;

const ACTUAL_FUNCTION = 'work/test_function_name.txt';
const ACTUAL_STATUS = 'work/test_status.txt';
const RECUR_HOURLY = 'hourly';
const STATUS_WAITING = 'waiting';
const STATUS_COMPLETED = 'completed';
const STATUS_RUNNING = 'running';
const STATUS_FAILED = 'failed';
const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

abstract class CavalcadeRunner_TestCase extends WP_UnitTestCase
{
    function setUp()
    {
        global $wpdb;

        parent::setUp();
        _set_cron_array(array());

        $wpdb->query("TRUNCATE {$wpdb->base_prefix}cavalcade_jobs");
        file_put_contents('work/restart.fifo', "\n");
        @unlink(ACTUAL_FUNCTION);
        @unlink(ACTUAL_STATUS);
    }

    public function start_transaction()
    {
        // Never use transaction.
    }
}
