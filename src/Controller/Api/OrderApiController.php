<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Sales\OrderModel;

class OrderApiController extends ApiController
{
    private const STATUSES = ['draft', 'confirmed', 'invoiced', 'cancelled'];

    private function orders(): OrderModel
    {
        return new OrderModel();
    }

    public function index(): void
    {
        $this->authenticate();

        $filters = $this->baseFilters() + [
            'partner_id' => (int) ($_GET['partner_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
        ];
        $pager = $this->paginator();
        $rows = $this->orders()->paginate($filters, $pager);

        $this->collection($rows, $pager);
    }

    public function show(int $id): void
    {
        $this->authenticate();

        $order = $this->orders()->findById($id);
        if (!$order) {
            $this->error('Order not found.', 404);
        }
        $this->resource($order);
    }

    public function create(): void
    {
        $this->authenticate();

        $body = $this->body();
        $partnerId = (int) ($body['partner_id'] ?? 0);
        if ($partnerId <= 0 || !(new PartnerModel())->findById($partnerId)) {
            $this->error('A valid partner_id is required.', 422);
        }
        $items = $this->mapItems($body['items'] ?? []);
        if (!$items) {
            $this->error('At least one valid line item (items) is required.', 422);
        }

        $id = $this->orders()->create([
            'order_number' => $this->orders()->nextOrderNumber(),
            'partner_id' => $partnerId,
            'shipping_address_id' => (int) ($body['shipping_address_id'] ?? 0),
            'billing_address_id' => (int) ($body['billing_address_id'] ?? 0),
            'status' => $this->statusOr($body, 'confirmed'),
            'order_date' => $this->dateOr($body['order_date'] ?? null),
            'shipping_cost' => (float) ($body['shipping_cost'] ?? 0),
            'payment_cost' => (float) ($body['payment_cost'] ?? 0),
            'created_by' => null,
        ], $items);

        $this->resource($this->orders()->findById($id), 201);
    }

    public function update(int $id): void
    {
        $this->authenticate();

        $order = $this->orders()->findById($id);
        if (!$order) {
            $this->error('Order not found.', 404);
        }

        if ($this->orders()->isLocked($id)) {
            $this->error('This order has been invoiced and can no longer be changed.', 409);
        }

        $body = $this->body();
        $partnerId = (int) ($body['partner_id'] ?? $order['partner_id']);
        if (!(new PartnerModel())->findById($partnerId)) {
            $this->error('A valid partner_id is required.', 422);
        }

        // Items only replaced when provided; otherwise kept as-is.
        $items = null;
        if (array_key_exists('items', $body)) {
            $items = $this->mapItems($body['items'] ?? []);
            if (!$items) {
                $this->error('When items is provided, at least one valid line item is required.', 422);
            }
        }

        $this->orders()->update($id, [
            'partner_id' => $partnerId,
            'shipping_address_id' => (int) ($body['shipping_address_id'] ?? $order['shipping_address_id']),
            'billing_address_id' => (int) ($body['billing_address_id'] ?? $order['billing_address_id']),
            'status' => $this->statusOr($body, $order['status']),
            'order_date' => $this->dateOr($body['order_date'] ?? $order['order_date']),
            'shipping_cost' => (float) ($body['shipping_cost'] ?? $order['shipping_cost']),
            'payment_cost' => (float) ($body['payment_cost'] ?? $order['payment_cost']),
        ], $items);

        $this->resource($this->orders()->findById($id));
    }

    public function delete(int $id): void
    {
        $this->authenticate();

        if (!$this->orders()->findById($id)) {
            $this->error('Order not found.', 404);
        }
        if (!$this->orders()->delete($id)) {
            $this->error('Only a draft or cancelled order that was never invoiced can be deleted.', 409);
        }
        $this->json(['data' => ['deleted' => true, 'id' => $id]]);
    }

    /** @return array<int, array{product_id:int, quantity:float, unit_price:float}> */
    private function mapItems(mixed $rawItems): array
    {
        if (!is_array($rawItems)) {
            return [];
        }
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            if ($productId > 0 && $quantity > 0) {
                $items[] = ['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $unitPrice];
            }
        }
        return $items;
    }

    private function statusOr(array $body, string $default): string
    {
        $status = $body['status'] ?? $default;
        // 'invoiced' comes from issuing the invoice, not from the order body.
        return in_array($status, self::STATUSES, true) && $status !== 'invoiced' ? $status : $default;
    }

    private function dateOr(?string $date): string
    {
        $date = trim((string) $date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }
}
