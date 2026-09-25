<?php

/**
 * Empties the app's generated directories under var/.
 *
 * By default only var/cache/ is cleared — that is the actual cache (Twig's
 * compiled templates, written when app.debug = 0). Logs and sessions are left
 * alone unless explicitly requested, because clearing sessions signs every
 * user out and logs are diagnostic data, not cache.
 *
 * The .gitkeep files are always preserved, and nothing outside var/ is touched.
 *
 * Usage:
 *   php bin/clear_cache.php                # Twig cache only
 *   php bin/clear_cache.php --logs         # + var/log
 *   php bin/clear_cache.php --sessions     # + var/sessions (signs everyone out!)
 *   php bin/clear_cache.php --all          # cache + logs + sessions
 *   php bin/clear_cache.php --dry-run      # only list what would be removed
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

$known = ['--logs', '--sessions', '--all', '--dry-run', '--help', '-h'];
foreach ($args as $arg) {
    if (!in_array($arg, $known, true)) {
        fwrite(STDERR, "Unknown option: $arg\nRun with --help to see the usage.\n");
        exit(1);
    }
}

if (array_intersect($args, ['--help', '-h'])) {
    $doc = file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    echo trim(preg_replace('#^\s*\*[ ]?#m', '', $m[1] ?? '')) . "\n";
    exit(0);
}

$all = in_array('--all', $args, true);
$dryRun = in_array('--dry-run', $args, true);

$targets = ['cache' => 'var/cache'];
if ($all || in_array('--logs', $args, true)) {
    $targets['logs'] = 'var/log';
}
if ($all || in_array('--sessions', $args, true)) {
    $targets['sessions'] = 'var/sessions';
}

/** Recursively deletes a directory's contents, keeping .gitkeep. */
function clearDir(string $dir, string $boundary, bool $dryRun, array &$stats): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        $real = realpath($path);

        // Never step outside the directory we were pointed at (symlink safety).
        if ($real === false || !str_starts_with($real, $boundary . DIRECTORY_SEPARATOR)) {
            fwrite(STDERR, 'Skipped (outside ' . basename($boundary) . "): $path\n");
            continue;
        }

        if ($item->getFilename() === '.gitkeep') {
            continue;
        }

        if ($item->isDir()) {
            $stats['dirs']++;
            if (!$dryRun && !@rmdir($path)) {
                fwrite(STDERR, "Could not remove directory: $path\n");
                $stats['errors']++;
            }
        } else {
            $stats['files']++;
            $stats['bytes'] += $item->getSize();
            if (!$dryRun && !@unlink($path)) {
                fwrite(STDERR, "Could not remove file: $path\n");
                $stats['errors']++;
            }
        }
    }
}

$total = ['files' => 0, 'dirs' => 0, 'bytes' => 0, 'errors' => 0];

foreach ($targets as $label => $relative) {
    $dir = $root . '/' . $relative;
    $boundary = realpath($dir);

    if ($boundary === false) {
        echo "- $relative: missing, skipped\n";
        continue;
    }

    $stats = ['files' => 0, 'dirs' => 0, 'bytes' => 0, 'errors' => 0];
    clearDir($dir, $boundary, $dryRun, $stats);

    printf(
        "- %-13s %d file%s%s%s\n",
        $relative . ':',
        $stats['files'],
        $stats['files'] === 1 ? '' : 's',
        $stats['dirs'] > 0 ? ", {$stats['dirs']} dir" . ($stats['dirs'] === 1 ? '' : 's') : '',
        $stats['bytes'] > 0 ? ' (' . round($stats['bytes'] / 1024, 1) . ' KB)' : ''
    );

    foreach ($total as $k => $_) {
        $total[$k] += $stats[$k];
    }
}

$verb = $dryRun ? 'Would remove' : 'Removed';
echo "$verb {$total['files']} file(s)"
    . ($total['dirs'] > 0 ? " and {$total['dirs']} directory(ies)" : '')
    . ', ' . round($total['bytes'] / 1024, 1) . " KB total.\n";

if (isset($targets['sessions']) && !$dryRun && $total['files'] > 0) {
    echo "Note: sessions were cleared — every signed-in user has been logged out.\n";
}

exit($total['errors'] > 0 ? 1 : 0);
