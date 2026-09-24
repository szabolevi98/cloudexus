<?php

namespace Cloudexus\Tests\Unit;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hungarian and English carry the same keys, file by file, and the labels the
 * code looks up by computed key (permissions, audit actions) all exist.
 */
final class LanguageFilesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../src/Language';

    /** @return iterable<string, array{string}> */
    public static function domains(): iterable
    {
        foreach (glob(self::DIR . '/hu/*.php') as $file) {
            yield basename($file, '.php') => [basename($file, '.php')];
        }
    }

    #[DataProvider('domains')]
    public function testHungarianAndEnglishHaveTheSameKeys(string $domain): void
    {
        $en = self::DIR . "/en/$domain.php";
        self::assertFileExists($en, "no English $domain.php");

        $huKeys = self::flatten(require self::DIR . "/hu/$domain.php");
        $enKeys = self::flatten(require $en);

        self::assertSame([], array_values(array_diff($huKeys, $enKeys)), "missing in en/$domain.php");
        self::assertSame([], array_values(array_diff($enKeys, $huKeys)), "missing in hu/$domain.php");
    }

    public function testEveryPermissionAndGroupHasALabel(): void
    {
        foreach (['hu', 'en'] as $lang) {
            $labels = require self::DIR . "/$lang/permissions.php";
            foreach (Permissions::groups() as $group => $keys) {
                self::assertArrayHasKey($group, $labels['groups'], "$lang: group $group");
                foreach ($keys as $key) {
                    [$area, $action] = explode('.', $key, 2);
                    self::assertIsString($labels['keys'][$area][$action] ?? null, "$lang: permission $key");
                }
            }
        }
    }

    public function testEveryAuditActionHasALabel(): void
    {
        foreach (['hu', 'en'] as $lang) {
            $labels = require self::DIR . "/$lang/audit.php";
            foreach (AuditLog::ACTIONS as $action) {
                self::assertIsString($labels['actions'][$action] ?? null, "$lang: audit action $action");
            }
        }
    }

    /** @return list<string> dotted keys of every leaf */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";
            if (is_array($value)) {
                array_push($keys, ...self::flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
