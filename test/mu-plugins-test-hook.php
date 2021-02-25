<?php

const ACTUAL_FUNCTION = '/workspace/work/test_function_name.txt';
const ACTUAL_STATUS = '/workspace/work/test_status.txt';
const JOB = 'test_job';
const FAILED_JOB = 'failed_test_job';
const WPTEST_WPCLI_FIFO = '/workspace/work/wptest-wpcli.fifo';
const WPCLI_WPTEST_FIFO = '/workspace/work/wpcli-wptest.fifo';

function go_wptest_blocking()
{
    file_put_contents(WPCLI_WPTEST_FIFO, "\n");
}

function wait_wptest_blocking()
{
    file_get_contents(WPTEST_WPCLI_FIFO);
}

if (defined('WP_CLI')) {
    WP_CLI::add_hook('before_invoke:cavalcade', function () {
        go_wptest_blocking();
        wait_wptest_blocking();
    });
}

add_action(JOB, function ($func) {
    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}cavalcade_jobs WHERE hook = %s",
        [JOB],
    );
    $result = $wpdb->get_results($sql)[0];

    file_put_contents(ACTUAL_FUNCTION, $func);
    file_put_contents(ACTUAL_STATUS, $result->status);
});

add_action(FAILED_JOB, function ($func) {
    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}cavalcade_jobs WHERE hook = %s",
        [FAILED_JOB],
    );
    $result = $wpdb->get_results($sql)[0];

    file_put_contents(ACTUAL_FUNCTION, $func);
    file_put_contents(ACTUAL_STATUS, $result->status);

    wp_die();
});
