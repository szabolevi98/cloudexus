<?php

/**
 * Minden Twig-sablon lefordítása az alkalmazás saját környezetével (a t(),
 * can(), sort_link() függvényekkel és a money, qty szűrőkkel). A phpunit a
 * sablonok nagy részét sosem rendereli, így egy elírt szűrő vagy egy lezáratlan
 * blokk különben csak élesben derülne ki. A CI és a deploy is futtatja.
 *
 *   php bin/check_templates.php
 */

use Cloudexus\Controller\BaseController;
use Cloudexus\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');

$controller = new class () extends BaseController {
    public function environment(): Twig\Environment
    {
        return $this->twig;
    }
};
$twig = $controller->environment();

$root = dirname(__DIR__) . '/src/View/Twig/';
$count = 0;
$bad = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'twig') {
        continue;
    }

    $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
    $count++;

    try {
        $twig->load($name);
    } catch (Throwable $e) {
        $bad++;
        fwrite(STDERR, $name . ': ' . $e->getMessage() . "\n");
    }
}

echo "$count templates, $bad bad\n";
exit($bad > 0 ? 1 : 0);
