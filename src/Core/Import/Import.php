<?php

namespace Cloudexus\Core\Import;

use Cloudexus\Core\Lang;

/**
 * Egy CSV-import két lépésben: előbb terv — soronként, hogy mi lesz belőle
 * (új, frissítés, nincs változás, hiba az okkal) —, és csak a jóváhagyás után
 * írás, egy tranzakcióban. A fájl oszlopait a fejlécük nevéről ismeri fel,
 * magyarul és angolul; amit nem ismer, azt kihagyja és megmondja.
 *
 * Frissítésnél csak a fájlban szereplő, nem üres cellák írnak: egy oszlop,
 * ami nincs a fájlban, vagy egy üres cella, a meglévő értéket hagyja.
 */
abstract class Import
{
    /** @return array<string, list<string>> mező => a fejlécek normalizált alakjai, amikről felismeri */
    abstract protected function columns(): array;

    /**
     * Egy sor terve.
     *
     * @param array<string, string> $values mező => a cella szövege (csak a fájlban szereplő mezők)
     * @return array{action: string, id: ?int, label: string, data: array<string, mixed>, messages: list<string>}
     */
    abstract protected function planRow(array $values, int $line): array;

    /** @param list<array<string, mixed>> $rows a terv írható sorai */
    abstract protected function write(array $rows): void;

    /** A mezők, amik nélkül a fájl nem importálható. @return list<string> */
    protected function requiredColumns(): array
    {
        return [];
    }

    /**
     * @param list<string> $headers
     * @param array<int, list<string>> $rows
     * @return array{columns: array<int, string>, ignored: list<string>, missing: list<string>, rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function plan(array $headers, array $rows): array
    {
        $columns = [];
        $ignored = [];
        foreach ($headers as $index => $header) {
            $field = $this->fieldFor($header);
            if ($field === null || in_array($field, $columns, true)) {
                if ($header !== '') {
                    $ignored[] = $header;
                }
                continue;
            }
            $columns[$index] = $field;
        }

        $missing = array_values(array_diff($this->requiredColumns(), $columns));
        $planned = [];
        if ($missing === []) {
            foreach ($rows as $line => $cells) {
                $values = [];
                foreach ($columns as $index => $field) {
                    $values[$field] = trim((string) ($cells[$index] ?? ''));
                }
                $planned[] = ['line' => $line] + $this->planRow($values, $line);
            }
        }

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($planned as $row) {
            $counts[$row['action']]++;
        }

        return ['columns' => $columns, 'ignored' => $ignored, 'missing' => $missing, 'rows' => $planned, 'counts' => $counts];
    }

    /**
     * A terv új és frissítendő sorai, egy tranzakcióban.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{create: int, update: int}
     */
    public function apply(array $rows): array
    {
        $writable = array_values(array_filter($rows, static fn(array $row): bool => in_array($row['action'], ['create', 'update'], true)));
        $pdo = \Cloudexus\Core\DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            $this->write($writable);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'create' => count(array_filter($writable, static fn(array $row): bool => $row['action'] === 'create')),
            'update' => count(array_filter($writable, static fn(array $row): bool => $row['action'] === 'update')),
        ];
    }

    /** @return list<string> a felismert fejlécek, az első alakjuk szerint — a súgónak */
    public function fields(): array
    {
        return array_keys($this->columns());
    }

    private function fieldFor(string $header): ?string
    {
        $normalized = CsvReader::normalize($header);
        foreach ($this->columns() as $field => $names) {
            if (in_array($normalized, $names, true)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<string, string|int> $replace */
    protected static function say(string $key, array $replace = []): string
    {
        return Lang::get('import.' . $key, array_map('strval', $replace));
    }
}
