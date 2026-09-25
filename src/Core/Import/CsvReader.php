<?php

namespace Cloudexus\Core\Import;

/**
 * Egy feltöltött CSV beolvasása úgy, ahogy az emberek küldik: a magyar Excel
 * pontosvesszővel és Windows-1250 kódolással ment, a Google Táblázatok
 * vesszővel és UTF-8-cal, néha BOM-mal, néha tabulátorral. Az elválasztót az
 * első sorból, a kódolást a tartalomból találja ki.
 */
final class CsvReader
{
    public const MAX_ROWS = 5000;

    /**
     * @return array{headers: list<string>, rows: array<int, list<string>>} a sorok a fájl sorszámával kulcsolva (a fejléc az 1.)
     * @throws \RuntimeException ha nem olvasható, üres, vagy túl hosszú
     */
    public static function read(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false || trim($text) === '') {
            throw new \RuntimeException('empty');
        }

        $text = self::toUtf8($text);
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $firstLine = strtok($text, "\r\n") ?: '';
        $delimiter = self::delimiter($firstLine);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('empty');
        }
        fwrite($handle, $text);
        rewind($handle);

        $headers = null;
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $cells = array_map(static fn($cell): string => trim((string) $cell), $cells);
            if ($headers === null) {
                $headers = $cells;
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new \RuntimeException('too_long');
            }
            $rows[$line] = $cells;
        }
        fclose($handle);

        if ($headers === null) {
            throw new \RuntimeException('empty');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Egy fejléc összehasonlítható alakja: kisbetű, ékezet és írásjel nélkül. "Nettó ár" → "nettoar". */
    public static function normalize(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        return (string) preg_replace('/[^a-z0-9]/', '', $header);
    }

    /**
     * Egy szám, ahogy egy táblázat írja: "12 345,50", "12345.5", "1.234,00" → 12345.5, vagy null.
     */
    public static function number(string $value): ?float
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', 'Ft', 'HUF', '%'], '', trim($value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            // Amelyik később jön, az a tizedesjel.
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Igen/nem, ahogy írni szokták — vagy null, ha egyik sem. */
    public static function yesNo(string $value): ?bool
    {
        $value = self::normalize($value);

        return match (true) {
            in_array($value, ['igen', 'i', 'yes', 'y', '1', 'true', 'x', 'aktiv', 'active'], true) => true,
            in_array($value, ['nem', 'n', 'no', '0', 'false', 'inaktiv', 'inactive'], true) => false,
            default => null,
        };
    }

    private static function delimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function toUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $text);

        return $converted === false ? mb_convert_encoding($text, 'UTF-8', 'ISO-8859-2') : $converted;
    }
}
