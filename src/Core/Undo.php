<?php

namespace Cloudexus\Core;

use PDO;

/**
 * Egy törlés visszavonása, tíz percig: a törlés előtt a sor és a hozzá
 * tartozó (vele együtt törlődő) sorok pillanatképe a munkamenetbe kerül, és
 * a következő oldalon egy "Visszavonás" gomb jelenik meg. A visszaállítás
 * ugyanazokkal az azonosítókkal, egy tranzakcióban írja vissza a sorokat, és
 * a törléskor NULL-ra állított hivatkozásokat (egy teendő partnere, egy
 * pénztárbizonylat partnere) is visszaköti.
 *
 * Mindig csak a legutóbbi törlés vonható vissza. Ha közben valami más foglalta
 * el a helyét (például egy új termék ugyanazzal a cikkszámmal), nem sikerül,
 * és ezt meg is mondja.
 */
final class Undo
{
    private const KEY = 'undo';
    public const MINUTES = 10;

    /**
     * A törlendő sorok eltétele, a törlés előtt.
     *
     * @param list<array{0: string, 1: string, 2: int}> $tables tábla, oszlop, érték — szülő előbb, gyerekek utána
     * @param list<array{0: string, 1: string, 2: int}> $relinks tábla, oszlop, érték: a törléskor NULL-ra állítódó hivatkozások
     */
    public static function capture(string $label, string $back, array $tables, array $relinks = []): void
    {
        $pdo = DatabaseConnection::get();
        $snapshot = [];
        foreach ($tables as [$table, $column, $value]) {
            $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$column` = :value");
            $stmt->execute(['value' => $value]);
            $snapshot[] = ['table' => $table, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }

        $links = [];
        foreach ($relinks as [$table, $column, $value]) {
            $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$column` = :value");
            $stmt->execute(['value' => $value]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $links[] = ['table' => $table, 'column' => $column, 'value' => $value, 'ids' => $ids];
            }
        }

        Session::set(self::KEY, [
            'token' => bin2hex(random_bytes(12)),
            'label' => $label,
            'back' => $back,
            'user' => Auth::id(),
            'expires' => time() + self::MINUTES * 60,
            'shown' => false,
            'snapshot' => $snapshot,
            'relinks' => $links,
        ]);
    }

    /** A törlés mégsem történt meg (pl. egy idegen kulcs megakadályozta): nincs mit visszavonni. */
    public static function forget(): void
    {
        Session::remove(self::KEY);
    }

    /**
     * A visszavonás ajánlata a törlés utáni első oldalon: a felirat és a
     * token, vagy null.
     *
     * @return array{token: string, label: string}|null
     */
    public static function offer(): ?array
    {
        $undo = self::current();
        if ($undo === null || $undo['shown']) {
            return null;
        }
        $undo['shown'] = true;
        Session::set(self::KEY, $undo);

        return ['token' => $undo['token'], 'label' => $undo['label']];
    }

    /**
     * Visszaírja a sorokat. Visszaadja, hová menjen utána, vagy null, ha a
     * token nem a legutóbbi törlésé, lejárt, vagy a visszaírás nem sikerült.
     */
    public static function restore(string $token): ?string
    {
        $undo = self::current();
        if ($undo === null || !hash_equals($undo['token'], $token)) {
            return null;
        }
        self::forget();

        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            foreach ($undo['snapshot'] as $part) {
                foreach ($part['rows'] as $row) {
                    $columns = array_keys($row);
                    $pdo->prepare(
                        'INSERT INTO `' . $part['table'] . '` (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')'
                    )->execute($row);
                }
            }
            foreach ($undo['relinks'] as $link) {
                $in = implode(', ', array_map('intval', $link['ids']));
                $pdo->prepare('UPDATE `' . $link['table'] . '` SET `' . $link['column'] . "` = :value WHERE id IN ($in) AND `" . $link['column'] . '` IS NULL')
                    ->execute(['value' => $link['value']]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            Logger::error('Undo failed: ' . $e->getMessage());

            return null;
        }

        return (string) $undo['back'];
    }

    /** @return array<string, mixed>|null a még érvényes, ennek a felhasználónak szóló visszavonás */
    private static function current(): ?array
    {
        $undo = Session::get(self::KEY);
        if (!is_array($undo) || ($undo['user'] ?? null) !== Auth::id() || (int) ($undo['expires'] ?? 0) < time()) {
            return null;
        }

        return $undo;
    }
}
