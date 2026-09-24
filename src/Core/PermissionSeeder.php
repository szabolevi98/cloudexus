<?php

namespace Cloudexus\Core;

/**
 * A kezdő jogosultság-mátrix betöltése — a database/migrate.php futtatja,
 * minden alkalommal, és mindig ugyanazt éri el:
 *
 * 1. Egy beépített szerepkör, amelyik még nem kapott kezdő jogokat
 *    (permissions_seeded_at üres), megkapja a Permissions::defaults() szerintit.
 * 2. Egy kódban újonnan megjelent kulcsot egyszer megkapnak azok a
 *    szerepkörök, amelyeknek a defaults() szerint jár. Hogy mi számít újnak,
 *    azt a legutóbb látott katalógus (settings: permissions.known) dönti el,
 *    így egy a felületen szándékosan elvett jogot nem ad vissza.
 * 3. A kódból már kikerült kulcsok sorai törlődnek.
 */
final class PermissionSeeder
{
    private const KNOWN_SETTING = 'permissions.known';

    /** @return array{seeded: int, granted: int, removed: int} */
    public static function run(): array
    {
        $pdo = DatabaseConnection::get();
        $defaults = Permissions::defaults();
        $catalog = Permissions::all();
        $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (:role, :permission)');
        $result = ['seeded' => 0, 'granted' => 0, 'removed' => 0];

        $known = self::known($pdo);
        $firstRun = $known === null;
        $newKeys = $firstRun ? [] : array_values(array_diff($catalog, $known));

        foreach ($pdo->query('SELECT id, code, permissions_seeded_at FROM roles')->fetchAll() as $role) {
            $keys = $defaults[$role['code']] ?? null;
            if ($keys === null) {
                continue;
            }

            if ($role['permissions_seeded_at'] === null) {
                foreach ($keys as $key) {
                    $insert->execute(['role' => $role['id'], 'permission' => $key]);
                }
                $pdo->prepare('UPDATE roles SET permissions_seeded_at = NOW() WHERE id = :id')->execute(['id' => $role['id']]);
                $result['seeded']++;
                continue;
            }

            foreach (array_intersect($newKeys, $keys) as $key) {
                $insert->execute(['role' => $role['id'], 'permission' => $key]);
                $result['granted'] += $insert->rowCount();
            }
        }

        $placeholders = implode(',', array_fill(0, count($catalog), '?'));
        $stale = $pdo->prepare("DELETE FROM role_permissions WHERE permission NOT IN ($placeholders)");
        $stale->execute($catalog);
        $result['removed'] = $stale->rowCount();

        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['k' => self::KNOWN_SETTING, 'v' => json_encode($catalog)]);

        return $result;
    }

    /** @return list<string>|null a legutóbb látott katalógus, vagy null, ha még sosem futott */
    private static function known(\PDO $pdo): ?array
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute(['k' => self::KNOWN_SETTING]);
        $value = $stmt->fetchColumn();
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
    }
}
