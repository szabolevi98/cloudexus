<?php

/**
 * Kiküldi a soron következő leveleket: amit az oldalak a sorba tettek, és
 * ami újrapróbálásra esedékes. Cronból, percenként:
 *
 *   * * * * * www-data php /var/www/cloudexus/bin/outbox.php
 *
 * A hónapnál régebbi elküldött leveleket is kitörli. -v: mit csinált.
 */

use Cloudexus\Core\Config;
use Cloudexus\Core\Outbox;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));

$outbox = new Outbox();
$sent = $outbox->sendDue();
$pruned = $outbox->prune();

if (in_array('-v', $argv, true)) {
    $counts = $outbox->counts();
    printf('%d sent, %d waiting, %d failed, %d old ones cleared.%s', $sent, $counts['waiting'], $counts['failed'], $pruned, PHP_EOL);
}
