<?php

/*
 * Router for PHP's built-in server, standing in for the .htaccess rewrite:
 * existing files under web/ are served as they are, everything else goes to
 * the front controller.
 *
 *   php -S 127.0.0.1:8080 -t web bin/dev-router.php
 *
 * With it, config.ini's base_url is the bare origin, e.g. http://127.0.0.1:8080.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(dirname(__DIR__) . '/web' . $path);
$webRoot = realpath(dirname(__DIR__) . '/web');

if ($path !== '/' && $file !== false && str_starts_with($file, $webRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $webRoot . '/index.php';
