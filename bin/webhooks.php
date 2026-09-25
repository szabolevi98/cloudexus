<?php

/**
 * Kiküldi az esedékes webhook-üzeneteket: az újakat és az újrapróbálandókat,
 * és előtte sorba teszi a legutóbbi futás óta történt készletváltozásokat.
 * Cronból, percenként:
 *
 *   * * * * * www-data php /var/www/cloudexus/bin/webhooks.php
 *
 * A 30 napnál régebbi, átment üzeneteket is törli. -v: mit csinált.
 */

use Cloudexus\Core\Config;
use Cloudexus\Core\Lang;
use Cloudexus\Core\Webhooks;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));
$default = (string) Config::get('app.default_locale', 'hu');
Lang::init($default, array_map('trim', explode(',', (string) Config::get('app.available_locales', 'hu,en'))), $default);

$webhooks = new Webhooks();
$stock = $webhooks->collectStockChanges();
$sent = $webhooks->sendDue();
$pruned = $webhooks->prune();

if (in_array('-v', $argv, true)) {
    printf('%d stock change(s) queued, %d delivered, %d old ones cleared.%s', $stock, $sent, $pruned, PHP_EOL);
}
