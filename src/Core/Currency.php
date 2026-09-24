<?php

namespace Cloudexus\Core;

use Cloudexus\Model\Core\CurrencyModel;
use Cloudexus\Model\Core\SettingModel;
use Throwable;

/**
 * Elsődleges pénznem és váltószámok.
 *
 * Az alkalmazás minden összeget az elsődleges pénznemben tárol és jelenít meg;
 * a többi pénznem váltószáma (value) csak tájékoztató, és a Beállítások >
 * Pénznemek oldalon látszik. A value OpenCart-logikát követ: 1 elsődleges
 * egység ennyi az adott pénznemben, tehát az átszámítás összeg * value.
 *
 * Az adatokat első használatkor tölti be, hogy egy sima kérés se fizesse ki a
 * lekérdezést, ha nincs kiírandó összeg.
 */
class Currency
{
    /** Használt érték, ha a pénznem tábla még nem létezik (pl. migráció előtt). */
    private const FALLBACK = ['code' => 'HUF', 'symbol' => 'Ft', 'value' => 1.0, 'title' => 'Forint'];

    private static ?array $primary = null;

    /** Az elsődleges pénznem sora (code, symbol, value, title). */
    public static function primary(): array
    {
        if (self::$primary === null) {
            self::$primary = self::loadPrimary();
        }

        return self::$primary;
    }

    public static function code(): string
    {
        return (string) self::primary()['code'];
    }

    /** A kiírandó jelölés: a symbol, ha van, egyébként a kód. */
    public static function symbol(): string
    {
        $primary = self::primary();
        return ($primary['symbol'] ?? '') !== '' ? (string) $primary['symbol'] : (string) $primary['code'];
    }

    /** Pénznemek, amelyeknek nincs váltópénze a gyakorlatban: egész összegekben számolunk. */
    private const WHOLE_UNITS = ['HUF', 'JPY', 'KRW', 'ISK', 'CLP'];

    /** Hány tizedesjegyre kerekítünk és írunk ki az elsődleges pénznemben (HUF: 0, EUR: 2). */
    public static function decimals(): int
    {
        return in_array(self::code(), self::WHOLE_UNITS, true) ? 0 : 2;
    }

    /** Kerekítés az elsődleges pénznem pontosságára — minden számított összeg így tárolódik. */
    public static function round(float|int|string|null $amount): float
    {
        return round((float) $amount, self::decimals());
    }

    /** Összeg formázása az elsődleges pénznemben, pl. "89 900 Ft" vagy "12,50 €". */
    public static function format(float|int|string|null $amount, ?int $decimals = null): string
    {
        return number_format((float) $amount, $decimals ?? self::decimals(), ',', ' ') . ' ' . self::symbol();
    }

    /** Elsődleges pénznemben megadott összeg átszámítása a megadott pénznemre. */
    public static function convert(float|int|string|null $amount, float $value): float
    {
        return (float) $amount * $value;
    }

    /** Az összes pénznem, mindegyiknél egy is_primary jelzéssel. */
    public static function all(): array
    {
        $primaryCode = self::code();

        return array_map(static function (array $row) use ($primaryCode): array {
            $row['is_primary'] = $row['code'] === $primaryCode;
            return $row;
        }, (new CurrencyModel())->all());
    }

    /** Újratöltésre kényszerít, ha a pénznemek menet közben módosultak. */
    public static function reset(): void
    {
        self::$primary = null;
    }

    private static function loadPrimary(): array
    {
        try {
            $code = (string) (new SettingModel())->get('currency.primary', self::FALLBACK['code']);
            $row = (new CurrencyModel())->findByCode($code);

            if ($row === null) {
                Logger::error('Az elsődleges pénznem (' . $code . ') nincs a currencies táblában.');
                return self::FALLBACK;
            }

            $row['value'] = (float) $row['value'];

            return $row;
        } catch (Throwable $e) {
            // Pl. friss telepítés migráció előtt: ne dőljön el tőle az oldal.
            Logger::error('Pénznem betöltése sikertelen: ' . $e->getMessage());
            return self::FALLBACK;
        }
    }
}
