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
    file_put_contents('/workspace/work/log/runner-started', "\n");
    file_put_contents('/workspace/work/log/runner-wptest.fifo', "\n");
    file_get_contents('/workspace/work/log/wptest-runner.fifo');
});

$runner->hooks->register('Runner.run_job.acquiring_lock', function ($db, $job) {
    switch ($job->hook) {
        case 'test_job_acquiring_lock_error':
            $stmt = $db->prepare("ALTER TABLE `wptests_cavalcade_jobs` MODIFY `status` enum('waiting','done') NOT NULL DEFAULT 'waiting'");
            $stmt->execute();
            break;
        case 'test_lost_connection_error':
            try {
                $stmt = $db->prepare("KILL CONNECTION_ID()");
                $stmt->execute();
            } catch (Exception $e) {
            }
            break;
        case 'test_packet_out_of_order_error':
            $db->set_next_error('Packets out of order. Expected 1 received 0. Packet size=30');
            break;
        case 'test_repeating_packet_out_of_order_error':
            $db->set_repeating_error('Packets out of order. Expected 1 received 0. Packet size=30');
            break;
        case 'test_unknown_php_error':
            $db->set_next_error('Unknown error');
            break;
        case 'test_repeating_unknown_php_error':
            $db->set_repeating_error('Unknown error');
            break;
    }
});

$runner->hooks->register('Runner.run_job.canceling_lock', function ($db, $job) {
    if ($job->hook === 'test_job_canceling_lock_error') {
        $stmt = $db->prepare("ALTER TABLE `wptests_cavalcade_jobs` MODIFY `status` enum('running','done') NOT NULL DEFAULT 'running'");
        $stmt->execute();
    }
});

$runner->hooks->register('Runner.job_command.command', function ($command, $job) {
    if ($job->hook === 'test_job_canceling_lock_error') {
        throw new Exception('exception for testing');
    }

    return $command;
});

$runner->hooks->register('Runner.check_workers.job_finishing', function ($db, $worker, $job) {
    if ($job->hook === 'test_job_finishing_error') {
        $stmt = $db->prepare("ALTER TABLE `wptests_cavalcade_jobs` MODIFY `status` enum('running','waiting') NOT NULL DEFAULT 'waiting'");
        $stmt->execute();
    }
});
EOS;

const CAVALCADE_GET_IP = <<<'EOS'
$get_current_ips = function () {
    if (file_exists('/workspace/work/log/get-current-ips-error')) {
        throw new MetadataError('failed to get URL: http://169.254.169.254/latest/meta-data/');
    }

    return [
        '192.0.0.8', // dummy IP address
        file_get_contents('/workspace/work/log/public-ip'),
    ];
};
EOS;

const CAVALCADE_PDOCLASS = <<<'EOS'
$pdoclass = 'HM\Cavalcade\Runner\PDOTester';
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

$cavalcade_for_testing = str_replace(
    '/*CAVALCADE_PDOCLASS_FOR_TESTING*/',
    CAVALCADE_PDOCLASS,
    $cavalcade_for_testing,
);

file_put_contents(CAVALCADE_TEST_BIN, $cavalcade_for_testing, 0);
chmod(CAVALCADE_TEST_BIN, 0755);
