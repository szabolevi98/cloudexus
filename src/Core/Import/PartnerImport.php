<?php

namespace Cloudexus\Core\Import;

use Cloudexus\Core\DatabaseConnection;
use PDO;

/**
 * Partnerek CSV-ből. A kulcs az adószám, ha a sorban van, különben a pontos
 * név: ami megvan, az frissül, ami nincs, az új lesz. Ugyanazok az oszlopok,
 * amiket a partnerek exportja ír.
 */
final class PartnerImport extends Import
{
    private PDO $db;

    /** @var array<string, int> kulcs => a fájl sora, ahol először szerepelt */
    private array $seen = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    protected function columns(): array
    {
        return [
            'name' => ['nev', 'partnernev', 'cegnev', 'megnevezes', 'name', 'partner', 'company'],
            'type' => ['tipus', 'type', 'szerep'],
            'tax_number' => ['adoszam', 'taxnumber', 'tax', 'vatnumber'],
            'email' => ['email', 'emailcim', 'mail'],
            'phone' => ['telefon', 'telefonszam', 'tel', 'phone'],
            'address' => ['cim', 'szekhely', 'address'],
            'is_active' => ['aktiv', 'active', 'allapot', 'status'],
        ];
    }

    protected function requiredColumns(): array
    {
        return ['name'];
    }

    protected function planRow(array $values, int $line): array
    {
        $name = mb_substr($values['name'] ?? '', 0, 200);
        $taxNumber = mb_substr($values['tax_number'] ?? '', 0, 32);
        $plan = static fn(string $action, ?int $id, array $data, array $messages = []) => ['action' => $action, 'id' => $id, 'label' => $name, 'data' => $data, 'messages' => $messages];

        if ($name === '') {
            return $plan('error', null, [], [self::say('name_missing')]);
        }

        $key = $taxNumber !== '' ? 'tax:' . $taxNumber : 'name:' . mb_strtolower($name);
        if (isset($this->seen[$key])) {
            return $plan('error', null, [], [self::say('duplicate', ['line' => $this->seen[$key]])]);
        }
        $this->seen[$key] = $line;

        $data = ['name' => $name];
        if ($taxNumber !== '') {
            $data['tax_number'] = $taxNumber;
        }
        if (($values['type'] ?? '') !== '') {
            $type = self::type($values['type']);
            if ($type === null) {
                return $plan('error', null, [], [self::say('bad_type', ['value' => $values['type']])]);
            }
            $data['type'] = $type;
        }
        if (($values['email'] ?? '') !== '') {
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                return $plan('error', null, [], [self::say('bad_email', ['value' => $values['email']])]);
            }
            $data['email'] = mb_substr($values['email'], 0, 190);
        }
        foreach (['phone' => 40, 'address' => 255] as $field => $length) {
            if (($values[$field] ?? '') !== '') {
                $data[$field] = mb_substr($values[$field], 0, $length);
            }
        }
        if (($values['is_active'] ?? '') !== '') {
            $flag = CsvReader::yesNo($values['is_active']);
            if ($flag === null) {
                return $plan('error', null, [], [self::say('bad_yes_no', ['value' => $values['is_active']])]);
            }
            $data['is_active'] = $flag ? 1 : 0;
        }

        $existing = $this->existing($taxNumber, $name);
        if ($existing === null) {
            return $plan('create', null, $data);
        }

        $changes = array_filter($data, static fn($value, string $field): bool => (string) ($existing[$field] ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH);

        return $plan($changes === [] ? 'unchanged' : 'update', (int) $existing['id'], $changes);
    }

    protected function write(array $rows): void
    {
        $insert = $this->db->prepare(
            'INSERT INTO partners (type, name, tax_number, email, phone, address, is_active, created_at)
             VALUES (:type, :name, :tax_number, :email, :phone, :address, :is_active, NOW())'
        );

        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['action'] === 'create') {
                $insert->execute([
                    'type' => $data['type'] ?? 'customer',
                    'name' => $data['name'],
                    'tax_number' => $data['tax_number'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'is_active' => $data['is_active'] ?? 1,
                ]);
                continue;
            }

            $columns = array_intersect_key($data, array_flip(['type', 'name', 'tax_number', 'email', 'phone', 'address', 'is_active']));
            if ($columns !== []) {
                $sets = implode(', ', array_map(static fn(string $c): string => "$c = :$c", array_keys($columns)));
                $this->db->prepare("UPDATE partners SET $sets WHERE id = :id")->execute($columns + ['id' => (int) $row['id']]);
            }
        }
    }

    /** "Vevő", "szállító", "mindkettő" — vagy az angol, vagy a rövidítés. */
    private static function type(string $value): ?string
    {
        $value = CsvReader::normalize($value);

        return match (true) {
            in_array($value, ['vevo', 'customer', 'v', 'c'], true) => 'customer',
            in_array($value, ['szallito', 'beszallito', 'supplier', 'sz', 's'], true) => 'supplier',
            in_array($value, ['mindketto', 'mindket', 'both', 'vevoesszallito', 'm', 'b'], true) => 'both',
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function existing(string $taxNumber, string $name): ?array
    {
        $stmt = $taxNumber !== ''
            ? $this->db->prepare('SELECT * FROM partners WHERE tax_number = :key ORDER BY id LIMIT 1')
            : $this->db->prepare('SELECT * FROM partners WHERE name = :key ORDER BY id LIMIT 1');
        $stmt->execute(['key' => $taxNumber !== '' ? $taxNumber : $name]);

        return $stmt->fetch() ?: null;
    }
}
