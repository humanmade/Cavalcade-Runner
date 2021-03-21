<?php

if ('1' === getenv('WP_MULTISITE')) {
    define('MULTISITE', true);
    define('WP_TESTS_MULTISITE', true);
}

require '/wp-tests/includes/functions.php';
require '/wp-tests/includes/bootstrap.php';
require __DIR__ . '/base.php';

const CAVALCADE_TEST_BIN = '/workspace/bin/cavalcade-runner-test';

$cavalcade_for_testing = file_get_contents('/workspace/bin/cavalcade-runner');

const CAVALCADE_HOOK = <<<'EOS'
$runner->hooks->register('Runner.run.before', function () {
    file_put_contents('/workspace/work/runner-started', "\n");
    file_put_contents('/workspace/work/runner-wptest.fifo', "\n");
});
EOS;

const CAVALCADE_GET_IP = <<<'EOS'
$get_current_ip = function () {
    return file_get_contents('/workspace/work/public-ip');
};
EOS;

$cavalcade_for_testing = str_replace(
    '/*CAVALCADE_HOOKS_FOR_TESTING*/',
    CAVALCADE_HOOK,
    $cavalcade_for_testing,
);

$cavalcade_for_testing = str_replace(
    '/*CAVALCADE_GET_IP_FOR_TESTING*/',
    CAVALCADE_GET_IP,
    $cavalcade_for_testing,
);

file_put_contents(CAVALCADE_TEST_BIN, $cavalcade_for_testing, 0);
chmod(CAVALCADE_TEST_BIN, 0755);
