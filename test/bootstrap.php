<?php

if ('1' === getenv('WP_MULTISITE')) {
    define('MULTISITE', true);
    define('WP_TESTS_MULTISITE', true);
}

require '/wp-tests/includes/functions.php';
require '/wp-tests/includes/bootstrap.php';
require __DIR__ . '/base.php';
