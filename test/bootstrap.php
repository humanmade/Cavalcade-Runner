<?php

if ('1' === getenv('WP_MULTISITE')) {
    define('MULTISITE', true);
    define('WP_TESTS_MULTISITE', true);
}

require '/wp-tests/includes/functions.php';
require '/wp-tests/includes/bootstrap.php';
require __DIR__ . '/base.php';

const CAVALCADE_TEST_BIN = '/workspace/bin/cavalcade-test';

$original_cavalcade = file_get_contents('/workspace/bin/cavalcade');

$cavalcade_hook = <<<'EOS'
$runner->hooks->register('Runner.run.before', function () {
    file_put_contents('/workspace/work/runner-wptest.fifo', "\n");
});
EOS;

$cavalcade_for_testing = str_replace(
    '/*CAVALCADE_HOOKS_FOR_TESTING*/',
    $cavalcade_hook,
    $original_cavalcade,
);
file_put_contents(CAVALCADE_TEST_BIN, $cavalcade_for_testing, 0);
chmod(CAVALCADE_TEST_BIN, 0755);
