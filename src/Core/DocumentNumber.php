<?php

namespace Cloudexus\Core;

use PDO;

/**
 * Hézagmentes, évenkénti bizonylatszámozás (SZLA-2026-0001, REND-2026-0001, …).
 *
 * Minden bizonylattípusnak évente egy számlálója van a document_sequences
 * táblában. A sorszámot a bizonylatot rögzítő tranzakción belül kérjük el:
 * a számláló sora zárolva van, amíg a tranzakció le nem zárul, így két
 * egyidejű mentés sem kaphatja ugyanazt a számot, és egy meghiúsult mentés
 * sem hagy lyukat, mert a számláló léptetése is visszagörgetődik vele.
 *
 * A számláló első használatkor a táblában már meglévő legnagyobb sorszámról
 * indul, így a korábbi — még darabszám alapján kiosztott — számok után
 * folytatódik. Egy törölt bizonylat száma soha nem kerül újra kiosztásra.
 */
final class DocumentNumber
{
    /** Típus => [előtag, tábla, sorszámoszlop]. */
    private const TYPES = [
        'invoice' => ['SZLA', 'invoices', 'invoice_number'],
        'order' => ['REND', 'orders', 'order_number'],
        'purchase_order' => ['BESZ', 'purchase_orders', 'po_number'],
        'incoming_invoice' => ['BSZLA', 'incoming_invoices', 'invoice_number'],
        'cash_voucher' => ['PB', 'cash_vouchers', 'voucher_number'],
        'stocktaking' => ['LELT', 'stocktakings', 'stocktaking_number'],
    ];

    /**
     * A következő sorszám, lefoglalva. Tranzakción belül hívandó; ha nincs
     * nyitott tranzakció, sajátot nyit a számláló léptetésére.
     */
    public static function take(string $type, ?string $date = null): string
    {
        [$prefix] = self::type($type);
        $year = self::year($date);
        $pdo = DatabaseConnection::get();
        $own = !$pdo->inTransaction();

        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->prepare('INSERT IGNORE INTO document_sequences (doc_type, year, last_number) VALUES (:type, :year, :last)')
                ->execute(['type' => $type, 'year' => $year, 'last' => self::highestUsed($pdo, $type, $year)]);

            $row = $pdo->prepare('SELECT last_number FROM document_sequences WHERE doc_type = :type AND year = :year FOR UPDATE');
            $row->execute(['type' => $type, 'year' => $year]);
            $next = (int) $row->fetchColumn() + 1;

            $pdo->prepare('UPDATE document_sequences SET last_number = :next WHERE doc_type = :type AND year = :year')
                ->execute(['next' => $next, 'type' => $type, 'year' => $year]);

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::format($prefix, $year, $next);
    }

    /**
     * A várható következő sorszám, lefoglalás nélkül — az űrlapokon
     * tájékoztatásnak. A valódi számot a mentés kapja.
     */
    public static function preview(string $type, ?string $date = null): string
    {
        [$prefix] = self::type($type);
        $year = self::year($date);
        $pdo = DatabaseConnection::get();

        $row = $pdo->prepare('SELECT last_number FROM document_sequences WHERE doc_type = :type AND year = :year');
        $row->execute(['type' => $type, 'year' => $year]);
        $last = $row->fetchColumn();

        return self::format($prefix, $year, ($last === false ? self::highestUsed($pdo, $type, $year) : (int) $last) + 1);
    }

    /** A táblában már kiosztott legnagyobb sorszám az adott évben. */
    private static function highestUsed(PDO $pdo, string $type, int $year): int
    {
        [$prefix, $table, $column] = self::type($type);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX($column, '-', -1) AS UNSIGNED)), 0)
             FROM $table WHERE $column LIKE :pattern"
        );
        $stmt->execute(['pattern' => $prefix . '-' . $year . '-%']);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function type(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown document type: ' . $type);
        }

        return self::TYPES[$type];
    }

    private static function year(?string $date): int
    {
        $time = $date !== null && $date !== '' ? strtotime($date) : false;

        return (int) date('Y', $time !== false ? $time : time());
    }

    private static function format(string $prefix, int $year, int $number): string
    {
        return sprintf('%s-%d-%04d', $prefix, $year, $number);
    }
}
