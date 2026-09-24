<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Cloudexus\Core\Config;
use Cloudexus\Core\DatabaseConnection;

Config::load(dirname(__DIR__) . '/config/config.ini');

$pdo = DatabaseConnection::get();
$files = glob(__DIR__ . '/*/*.sql');

sort($files);

foreach ($files as $file) {
    echo "Running $file ...\n";
    $pdo->exec(file_get_contents($file));
}

// A kezdő jogosultság-mátrix: szerepkörönként egyszer, az új kulcsok is egyszer.
$seeded = \Cloudexus\Core\PermissionSeeder::run();
printf(
    "Permissions: %d role(s) seeded, %d new grant(s), %d stale row(s) removed.\n",
    $seeded['seeded'],
    $seeded['granted'],
    $seeded['removed']
);

echo "Done.\n";
