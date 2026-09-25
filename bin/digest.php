<?php

/**
 * A reggeli összefoglaló mindenkinek, aki kérte (Profil → Reggeli
 * összefoglaló): ami ma figyelmet kér, abból, amit a szerepköre láthat. Üres
 * napon nem megy levél. Cronból, hétköznap reggel:
 *
 *   30 7 * * 1-5 www-data php /var/www/cloudexus/bin/digest.php
 *
 * A levelek a sorba kerülnek; a bin/outbox.php küldi ki őket. -v: mit csinált.
 */

use Cloudexus\Core\Config;
use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Digest;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Language;
use Cloudexus\Core\Mailer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));

// A felhasználók nyelvét nem tároljuk: a telepítés alapnyelvén.
$default = (string) Config::get('app.default_locale', 'hu');
Lang::init($default, array_map('trim', explode(',', (string) Config::get('app.available_locales', 'hu,en'))), $default);
Language::init($default);

if (!Mailer::isConfigured()) {
    in_array('-v', $argv, true) && print("Email is turned off; no digests.\n");
    exit(0);
}

$users = DatabaseConnection::get()->query('SELECT * FROM users WHERE is_active = 1 AND digest_enabled = 1 ORDER BY id');
$digest = new Digest();
$sent = 0;
$empty = 0;

foreach ($users === false ? [] : $users->fetchAll() as $user) {
    $message = $digest->forUser($user);
    if ($message === null) {
        $empty++;
        continue;
    }
    $sent += Mailer::send((string) $user['email'], (string) $user['full_name'], $message['subject'], $message['body'], 'digest') ? 1 : 0;
}

if (in_array('-v', $argv, true)) {
    printf('%d digest(s) queued, %d user(s) with nothing to report.%s', $sent, $empty, PHP_EOL);
}
