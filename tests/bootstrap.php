<?php

/*
 * Loads the autoloader and the test configuration.
 *
 * The configuration is the test database's, never the working one: the
 * integration tests delete the business data before each test. The guard
 * below refuses a database whose name does not end in "_test", the one
 * mistake that would make that deletion somebody's real data.
 */

use Cloudexus\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Budapest');

$config = getenv('CLOUDEXUS_TEST_CONFIG') ?: dirname(__DIR__) . '/config/test.ini';

if (is_file($config)) {
    Config::load($config);

    $name = (string) Config::get('database.name', '');
    if (!str_ends_with($name, '_test')) {
        fwrite(STDERR, "The test database must be named something_test; $config names \"$name\".\n");
        exit(1);
    }

    define('CLOUDEXUS_TEST_CONFIG', $config);
}
