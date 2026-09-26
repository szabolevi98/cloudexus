<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;

/**
 * Az üzletek (pipeline) — lásd a 20_deals.sql migrációt.
 */
class DealModel
{
    /** A szakaszok sorrendben, a saját alap-valószínűségükkel (%). */
    public const STAGES = [
        'lead' => 10,
        'qualified' => 25,
        'proposal' => 50,
        'negotiation' => 75,
        'won' => 100,
        'lost' => 0,
    ];

    public const OPEN_STAGES = ['lead', 'qualified', 'proposal', 'negotiation'];

    /** A megnyert és elvesztett oszlop csak az ennyi napon belül lezártakat mutatja. */
    public const CLOSED_DAYS = 30;

    private const SELECT = 'SELECT d.*, p.name AS partner_name, u.full_name AS owner_name,
                                   q.quote_number, q.status AS quote_status, o.order_number
                            FROM deals d
                            JOIN partners p ON p.id = d.partner_id
                            LEFT JOIN users u ON u.id = d.owner_id
                            LEFT JOIN quotes q ON q.id = d.quote_id
                            LEFT JOIN orders o ON o.id = d.order_id';

    public static function isStage(string $stage): bool
    {
        return array_key_exists($stage, self::STAGES);
    }

    public static function isOpen(string $stage): bool
    {
        return in_array($stage, self::OPEN_STAGES, true);
    }

    /**
     * Az üzlet valószínűsége: a saját, ha meg van adva, különben a szakaszé.
     * Lezárt üzletnél mindig a szakaszé (100 vagy 0).
     *
     * @param array<string, mixed> $deal
     */
    public static function probability(array $deal): int
    {
        $stage = (string) $deal['stage'];
        if (!self::isOpen($stage) || $deal['probability'] === null) {
            return self::STAGES[$stage] ?? 0;
        }

        return (int) $deal['probability'];
    }

    /**
     * A tábla: szakaszonként a kártyák és az oszlop összegei (darab, érték,
     * súlyozott érték).
     *
     * @param array{q?: string, owner_id?: int, partner_id?: int} $filters
     * @return array<string, array{deals: list<array<string, mixed>>, count: int, amount: float, weighted: float}>
     */
    public function board(array $filters = []): array
    {
        $where = ["(d.stage IN ('lead','qualified','proposal','negotiation') OR d.closed_at >= NOW() - INTERVAL " . self::CLOSED_DAYS . ' DAY)'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(d.title LIKE :q1 OR p.name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['owner_id'])) {
            $where[] = 'd.owner_id = :owner';
            $params['owner'] = (int) $filters['owner_id'];
        }
        if (!empty($filters['partner_id'])) {
            $where[] = 'd.partner_id = :partner';
            $params['partner'] = (int) $filters['partner_id'];
        }

        $stmt = DatabaseConnection::get()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.position ASC, d.id DESC');
        $stmt->execute($params);

        $board = [];
        foreach (array_keys(self::STAGES) as $stage) {
            $board[$stage] = ['deals' => [], 'count' => 0, 'amount' => 0.0, 'weighted' => 0.0];
        }
        foreach ($stmt->fetchAll() as $deal) {
            $deal = $this->decorate($deal);
            $column = &$board[$deal['stage']];
            $column['deals'][] = $deal;
            $column['count']++;
            $column['amount'] += (float) $deal['amount'];
            $column['weighted'] += $deal['weighted'];
            unset($column);
        }

        return $board;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(self::SELECT . ' WHERE d.id = :id');
        $stmt->execute(['id' => $id]);
        $deal = $stmt->fetch();

        return $deal ? $this->decorate($deal) : null;
    }

    /** @return list<array<string, mixed>> A partner üzletei, a nyitottak elöl. */
    public function forPartner(int $partnerId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            self::SELECT . " WHERE d.partner_id = :partner
             ORDER BY d.stage IN ('won','lost'), d.expected_close IS NULL, d.expected_close, d.id DESC"
        );
        $stmt->execute(['partner' => $partnerId]);

        return array_map(fn(array $deal): array => $this->decorate($deal), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null Az üzlet, amihez az ajánlat tartozik. */
    public function findByQuote(int $quoteId): ?array
    {
        $stmt = DatabaseConnection::get()->prepare(self::SELECT . ' WHERE d.quote_id = :quote ORDER BY d.id DESC LIMIT 1');
        $stmt->execute(['quote' => $quoteId]);
        $deal = $stmt->fetch();

        return $deal ? $this->decorate($deal) : null;
    }

    /**
     * @param array{title: string, partner_id: int, stage: string, amount: float, probability: ?int, expected_close: ?string, owner_id: ?int, note: string, created_by?: ?int} $data
     */
    public function create(array $data): int
    {
        $pdo = DatabaseConnection::get();
        // Az új kártya az oszlopa tetejére kerül.
        $top = $pdo->prepare('SELECT COALESCE(MIN(position), 0) - 1 FROM deals WHERE stage = :stage');
        $top->execute(['stage' => $data['stage']]);

        $pdo->prepare(
            'INSERT INTO deals (title, partner_id, stage, amount, probability, expected_close, owner_id, note, position, closed_at, created_by, created_at)
             VALUES (:title, :partner_id, :stage, :amount, :probability, :expected_close, :owner_id, :note, :position, :closed_at, :created_by, NOW())'
        )->execute([
            'title' => $data['title'],
            'partner_id' => $data['partner_id'],
            'stage' => $data['stage'],
            'amount' => $data['amount'],
            'probability' => $data['probability'],
            'expected_close' => $data['expected_close'],
            'owner_id' => $data['owner_id'],
            'note' => $data['note'] !== '' ? $data['note'] : null,
            'position' => (int) $top->fetchColumn(),
            'closed_at' => self::isOpen($data['stage']) ? null : date('Y-m-d H:i:s'),
            'created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Az adatok módosítása; a szakaszt a move() váltja.
     *
     * @param array{title: string, partner_id: int, amount: float, probability: ?int, expected_close: ?string, owner_id: ?int, note: string} $data
     */
    public function update(int $id, array $data): void
    {
        DatabaseConnection::get()->prepare(
            'UPDATE deals SET title = :title, partner_id = :partner_id, amount = :amount, probability = :probability,
                expected_close = :expected_close, owner_id = :owner_id, note = :note
             WHERE id = :id'
        )->execute([
            'title' => $data['title'],
            'partner_id' => $data['partner_id'],
            'amount' => $data['amount'],
            'probability' => $data['probability'],
            'expected_close' => $data['expected_close'],
            'owner_id' => $data['owner_id'],
            'note' => $data['note'] !== '' ? $data['note'] : null,
            'id' => $id,
        ]);
    }

    /**
     * Áthelyezés egy szakaszba, és ha az oszlop új sorrendje is megvan
     * ($order: az oszlop kártyáinak azonosítói fentről lefelé), a helye is.
     * Elvesztett szakaszba csak okkal lehet; kilépve belőle az ok törlődik.
     * Hamis, ha nincs ilyen üzlet, vagy az elvesztéshez hiányzik az ok.
     *
     * @param list<int> $order
     */
    public function move(int $id, string $stage, array $order = [], ?string $lostReason = null): bool
    {
        if (!self::isStage($stage) || ($stage === 'lost' && ($lostReason === null || $lostReason === ''))) {
            return false;
        }

        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            $current = $pdo->prepare('SELECT stage, lost_reason FROM deals WHERE id = :id FOR UPDATE');
            $current->execute(['id' => $id]);
            $row = $current->fetch();
            if (!$row) {
                $pdo->rollBack();

                return false;
            }

            if ($row['stage'] !== $stage) {
                $top = $pdo->prepare('SELECT COALESCE(MIN(position), 0) - 1 FROM deals WHERE stage = :stage');
                $top->execute(['stage' => $stage]);
                $pdo->prepare(
                    'UPDATE deals SET stage = :stage, lost_reason = :reason, closed_at = :closed, position = :position WHERE id = :id'
                )->execute([
                    'stage' => $stage,
                    'position' => (int) $top->fetchColumn(),
                    'reason' => $stage === 'lost' ? $lostReason : null,
                    'closed' => self::isOpen($stage) ? null : date('Y-m-d H:i:s'),
                    'id' => $id,
                ]);
            } elseif ($stage === 'lost' && $lostReason !== $row['lost_reason']) {
                $pdo->prepare('UPDATE deals SET lost_reason = :reason WHERE id = :id')->execute(['reason' => $lostReason, 'id' => $id]);
            }

            if ($order !== []) {
                $position = $pdo->prepare('UPDATE deals SET position = :position WHERE id = :id AND stage = :stage');
                foreach ($order as $index => $dealId) {
                    $position->execute(['position' => $index, 'id' => (int) $dealId, 'stage' => $stage]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Az üzlethez köt egy árajánlatot. Egy korai szakaszban lévő üzlet ettől
     * ajánlattételbe lép, és ha még nem volt értéke, az ajánlat nettója lesz.
     */
    public function attachQuote(int $id, int $quoteId): void
    {
        DatabaseConnection::get()->prepare(
            "UPDATE deals d JOIN quotes q ON q.id = :quote
             SET d.quote_id = q.id,
                 d.amount = IF(d.amount = 0, q.net_total, d.amount),
                 d.stage = IF(d.stage IN ('lead','qualified'), 'proposal', d.stage),
                 d.probability = IF(d.stage IN ('lead','qualified'), NULL, d.probability)
             WHERE d.id = :id AND d.stage NOT IN ('won','lost')"
        )->execute(['quote' => $quoteId, 'id' => $id]);
    }

    /**
     * Az ajánlatból rendelés lett: az üzlet megnyerve, a rendeléssel. A
     * QuoteModel::toOrder hívja a saját tranzakciójában.
     */
    public static function winFromQuote(int $quoteId, int $orderId): void
    {
        DatabaseConnection::get()->prepare(
            "UPDATE deals SET stage = 'won', order_id = :order, lost_reason = NULL, closed_at = NOW()
             WHERE quote_id = :quote AND stage <> 'won'"
        )->execute(['order' => $orderId, 'quote' => $quoteId]);
    }

    /**
     * Kereső a választókhoz (select2): a nyitott üzletek, cím vagy partner szerint.
     *
     * @return array{results: list<array{id: int, text: string}>, more: bool}
     */
    public function search(string $q, int $page = 1, int $perPage = 20): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT d.id, d.title, p.name AS partner FROM deals d JOIN partners p ON p.id = d.partner_id
             WHERE d.stage IN ('lead','qualified','proposal','negotiation') AND (d.title LIKE :q1 OR p.name LIKE :q2)
             ORDER BY d.id DESC LIMIT " . ($perPage + 1) . ' OFFSET ' . (max(1, $page) - 1) * $perPage
        );
        $stmt->execute(['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%']);
        $rows = $stmt->fetchAll();

        return [
            'results' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'text' => $r['title'] . ' — ' . $r['partner']], array_slice($rows, 0, $perPage)),
            'more' => count($rows) > $perPage,
        ];
    }

    /** @return list<array{id: int, text: string}> A választó kiválasztott eleme, a kereső formájában. */
    public function labelsForIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $stmt = DatabaseConnection::get()->query(
            'SELECT d.id, d.title, p.name AS partner FROM deals d JOIN partners p ON p.id = d.partner_id WHERE d.id IN (' . implode(',', $ids) . ')'
        );

        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'text' => $r['title'] . ' — ' . $r['partner']], $stmt->fetchAll());
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM deals WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $deal
     * @return array<string, mixed>
     */
    private function decorate(array $deal): array
    {
        $deal['chance'] = self::probability($deal);
        $deal['weighted'] = round((float) $deal['amount'] * $deal['chance'] / 100, 2);
        $deal['is_open'] = self::isOpen((string) $deal['stage']);
        $deal['is_late'] = $deal['is_open'] && $deal['expected_close'] !== null && (string) $deal['expected_close'] < date('Y-m-d');

        return $deal;
    }
}
