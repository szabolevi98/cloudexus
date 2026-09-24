<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\DatabaseConnection;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\IdempotencyKeyModel;
use Cloudexus\Model\Core\LocationModel;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\StockMovementModel;
use Cloudexus\Model\Core\WarehouseModel;

/**
 * Current stock (read), and booking stock in, out and between warehouses
 * (write) for the mobile / PDA app. A booking carries any number of lines and
 * is all-or-nothing: one short line rejects the whole request. The rules match
 * the admin UI: stock out is checked against the warehouse's stock, and a
 * transfer's note starts with "Raktárközi átadás" like the ones booked there.
 */
class StockApiController extends ApiController
{
    private const MAX_ITEMS = 500;
    private const MAX_NOTE_LENGTH = 200;
    private const DEFAULT_NOTE = 'Mobil app';

    private StockMovementModel $movements;

    public function __construct()
    {
        $this->movements = new StockMovementModel();
    }

    public function index(): void
    {
        $this->authenticate();

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'location_id' => (int) ($_GET['location_id'] ?? 0),
            'product_id' => (int) ($_GET['product_id'] ?? 0),
        ];
        $pager = $this->paginator();
        $rows = $this->movements->overview($filters, $pager);

        $this->collection($rows, $pager);
    }

    public function in(): void
    {
        $this->bookInOrOut('in');
    }

    public function out(): void
    {
        $this->bookInOrOut('out');
    }

    public function transfer(): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $body = $this->body();
        $from = $this->activeWarehouse($body['from_warehouse_id'] ?? null, 'from_warehouse_id');
        $to = $this->activeWarehouse($body['to_warehouse_id'] ?? null, 'to_warehouse_id');
        if ((int) $from['id'] === (int) $to['id']) {
            $this->error('from_warehouse_id and to_warehouse_id must differ.', 422);
        }
        $defaultFrom = $this->location($from, $body['from_location_id'] ?? null, 'from_location_id');
        $defaultTo = $this->location($to, $body['to_location_id'] ?? null, 'to_location_id');
        $note = $this->note($body);

        $lines = [];
        foreach ($this->items($body) as $i => $item) {
            $fromLocation = array_key_exists('from_location_id', $item['raw'])
                ? $this->location($from, $item['raw']['from_location_id'], "items[$i].from_location_id")
                : $defaultFrom;
            $toLocation = array_key_exists('to_location_id', $item['raw'])
                ? $this->location($to, $item['raw']['to_location_id'], "items[$i].to_location_id")
                : $defaultTo;

            $key = $item['product']['id'] . ':' . ($fromLocation['id'] ?? '') . ':' . ($toLocation['id'] ?? '');
            $lines[$key] ??= ['product' => $item['product'], 'from' => $fromLocation, 'to' => $toLocation, 'quantity' => 0.0];
            $lines[$key]['quantity'] += $item['quantity'];
        }

        $fullNote = 'Raktárközi átadás: ' . $from['name'] . ' → ' . $to['name'] . ($note !== null ? ' — ' . $note : '');

        $this->inTransaction(function () use ($from, $to, $lines, $fullNote): array {
            $this->movements->lockWarehouses([$from['id'], $to['id']]);

            $shortages = $this->shortages((int) $from['id'], $lines);
            if ($shortages) {
                return $this->shortageError($from, $shortages);
            }

            $transfers = [];
            foreach ($lines as $line) {
                $movement = fn(string $type, array $warehouse, ?array $location): int => $this->movements->create([
                    'warehouse_id' => (int) $warehouse['id'],
                    'location_id' => $location['id'] ?? null,
                    'product_id' => (int) $line['product']['id'],
                    'type' => $type,
                    'quantity' => $line['quantity'],
                    'note' => $fullNote,
                    'created_by' => (int) $this->user['id'],
                ]);

                $transfers[] = $this->productFields($line['product']) + [
                    'quantity' => self::qty($line['quantity']),
                    'out_movement_id' => $movement('out', $from, $line['from']),
                    'from_location_id' => $line['from']['id'] ?? null,
                    'from_location_code' => $line['from']['code'] ?? null,
                    'in_movement_id' => $movement('in', $to, $line['to']),
                    'to_location_id' => $line['to']['id'] ?? null,
                    'to_location_code' => $line['to']['code'] ?? null,
                ];
            }

            return [201, ['data' => [
                'from_warehouse' => $this->warehouseFields($from),
                'to_warehouse' => $this->warehouseFields($to),
                'note' => $fullNote,
                'created_by' => $this->createdBy(),
                'transfers' => $transfers,
            ]]];
        });
    }

    private function bookInOrOut(string $type): void
    {
        $this->requireUserPermission(Permissions::STOCK_MOVE);

        $body = $this->body();
        $warehouse = $this->activeWarehouse($body['warehouse_id'] ?? null, 'warehouse_id');
        $defaultLocation = $this->location($warehouse, $body['location_id'] ?? null, 'location_id');
        $note = $this->note($body) ?? self::DEFAULT_NOTE;

        // Lines for the same product and location are booked as one movement.
        $lines = [];
        foreach ($this->items($body) as $i => $item) {
            $location = array_key_exists('location_id', $item['raw'])
                ? $this->location($warehouse, $item['raw']['location_id'], "items[$i].location_id")
                : $defaultLocation;

            $key = $item['product']['id'] . ':' . ($location['id'] ?? '');
            $lines[$key] ??= ['product' => $item['product'], 'location' => $location, 'quantity' => 0.0];
            $lines[$key]['quantity'] += $item['quantity'];
        }

        $this->inTransaction(function () use ($type, $warehouse, $lines, $note): array {
            $this->movements->lockWarehouses([$warehouse['id']]);

            if ($type === 'out') {
                $shortages = $this->shortages((int) $warehouse['id'], $lines);
                if ($shortages) {
                    return $this->shortageError($warehouse, $shortages);
                }
            }

            $movements = [];
            foreach ($lines as $line) {
                $id = $this->movements->create([
                    'warehouse_id' => (int) $warehouse['id'],
                    'location_id' => $line['location']['id'] ?? null,
                    'product_id' => (int) $line['product']['id'],
                    'type' => $type,
                    'quantity' => $line['quantity'],
                    'note' => $note,
                    'created_by' => (int) $this->user['id'],
                ]);
                $movements[] = ['id' => $id] + $this->productFields($line['product']) + [
                    'location_id' => $line['location']['id'] ?? null,
                    'location_code' => $line['location']['code'] ?? null,
                    'quantity' => self::qty($line['quantity']),
                ];
            }

            return [201, ['data' => [
                'type' => $type,
                'warehouse' => $this->warehouseFields($warehouse),
                'note' => $note,
                'created_by' => $this->createdBy(),
                'movements' => $movements,
            ]]];
        });
    }

    /**
     * Runs a booking in one transaction and outputs its result. $work returns
     * [status, body]; a status of 400 or more rolls the booking back.
     *
     * With an Idempotency-Key header, a retry of a request that already booked
     * gets the stored response back (with an Idempotent-Replayed: true header)
     * instead of booking twice. Failed requests are not stored, so a client can
     * fix the problem and retry under the same key.
     */
    private function inTransaction(callable $work): never
    {
        $key = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            $this->error('Idempotency-Key must be 8-64 characters of A-Z, a-z, 0-9, _ or -.', 422);
        }
        $ownerKey = 'u' . $this->user['id'];
        $requestHash = hash('sha256', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $this->requestPath() . "\n" . file_get_contents('php://input'));

        $pdo = DatabaseConnection::get();
        // Every read sees the latest committed stock, not a snapshot from before the warehouse lock.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();

        try {
            $idempotency = new IdempotencyKeyModel();
            $stored = $key !== '' ? $idempotency->claim($ownerKey, $key, $requestHash) : null;
            if ($stored) {
                $pdo->rollBack();
                if (!hash_equals($stored['request_hash'], $requestHash)) {
                    $this->error('This Idempotency-Key was already used for a different request.', 422);
                }
                header('Idempotent-Replayed: true');
                $this->json(json_decode($stored['response'], true), (int) $stored['status_code']);
            }

            [$status, $response] = $work();

            if ($status >= 400) {
                $pdo->rollBack();
                $this->json($response, $status);
            }

            if ($key !== '') {
                $idempotency->complete($ownerKey, $key, $status, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->json($response, $status);
    }

    /**
     * Validates body.items: a non-empty list of {product_id, quantity} where the
     * product is active and the quantity positive with at most 3 decimals.
     * Every bad line is reported at once, so the app can mark them all.
     *
     * @return array<int, array{product: array, quantity: float, raw: array}>
     */
    private function items(array $body): array
    {
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || !$raw) {
            $this->error('items must be a non-empty list of {product_id, quantity}.', 422);
        }
        if (count($raw) > self::MAX_ITEMS) {
            $this->error('At most ' . self::MAX_ITEMS . ' items can be booked in one request.', 422);
        }

        $products = new ProductModel();
        $items = [];
        $problems = [];
        foreach ($raw as $i => $item) {
            if (!is_array($item)) {
                $problems[] = ['index' => $i, 'message' => 'Each item must be an object.'];
                continue;
            }
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $product = $productId ? $products->findById($productId) : null;
            if (!$product || !$product['is_active']) {
                $problems[] = ['index' => $i, 'message' => 'Unknown or inactive product_id.'];
                continue;
            }
            $quantity = self::parseQuantity($item['quantity'] ?? null);
            if ($quantity === null) {
                $problems[] = ['index' => $i, 'message' => 'quantity must be a positive number with at most 3 decimals.'];
                continue;
            }
            $items[$i] = ['product' => $product, 'quantity' => $quantity, 'raw' => $item];
        }

        if ($problems) {
            $this->error('Some items are invalid.', 422, $problems);
        }

        return $items;
    }

    private static function parseQuantity(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $quantity = (float) $value;
        if ($quantity <= 0 || $quantity >= 1e11 || abs($quantity * 1000 - round($quantity * 1000)) > 1e-6) {
            return null;
        }

        return round($quantity, 3);
    }

    private function activeWarehouse(mixed $id, string $field): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $warehouse = $id ? (new WarehouseModel())->findById($id) : null;
        if (!$warehouse || !$warehouse['is_active']) {
            $this->error("$field must be an active warehouse.", 422);
        }

        return $warehouse;
    }

    /** An optional location of the warehouse: null or 0 means "no location". */
    private function location(array $warehouse, mixed $id, string $field): ?array
    {
        if ($id === null || $id === 0 || $id === '0' || $id === '') {
            return null;
        }
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $location = $id ? (new LocationModel())->findById($id) : null;
        if (!$location || !$location['is_active'] || (int) $location['warehouse_id'] !== (int) $warehouse['id']) {
            $this->error("$field must be an active location of warehouse " . $warehouse['id'] . '.', 422);
        }

        return $location;
    }

    private function note(array $body): ?string
    {
        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $this->error('note can be at most ' . self::MAX_NOTE_LENGTH . ' characters.', 422);
        }

        return $note !== '' ? $note : null;
    }

    /**
     * Products whose requested total exceeds the warehouse's stock. Must run
     * after lockWarehouses(), inside the booking transaction.
     */
    private function shortages(int $warehouseId, array $lines): array
    {
        $requested = [];
        foreach ($lines as $line) {
            $id = (int) $line['product']['id'];
            $requested[$id] ??= ['product' => $line['product'], 'quantity' => 0.0];
            $requested[$id]['quantity'] += $line['quantity'];
        }

        $shortages = [];
        foreach ($requested as $id => $request) {
            $available = $this->movements->availableQuantity($id, $warehouseId);
            if (round($request['quantity'], 3) > round($available, 3)) {
                $shortages[] = $this->productFields($request['product']) + [
                    'available' => self::qty($available),
                    'requested' => self::qty($request['quantity']),
                ];
            }
        }

        return $shortages;
    }

    private function shortageError(array $warehouse, array $shortages): array
    {
        return [422, ['error' => [
            'status' => 422,
            'message' => 'Not enough stock in ' . $warehouse['name'] . ' for ' . count($shortages) . ' product(s). Nothing was booked.',
            'details' => $shortages,
        ]]];
    }

    /** findById() already carries the translated name and the unit code. */
    private function productFields(array $product): array
    {
        return [
            'product_id' => (int) $product['id'],
            'sku' => $product['sku'],
            'product_name' => $product['name'],
            'unit' => $product['unit'],
        ];
    }

    private function warehouseFields(array $warehouse): array
    {
        return ['id' => (int) $warehouse['id'], 'name' => $warehouse['name']];
    }

    private function createdBy(): array
    {
        return ['id' => (int) $this->user['id'], 'full_name' => $this->user['full_name']];
    }

    private static function qty(float $quantity): string
    {
        return number_format($quantity, 3, '.', '');
    }
}
