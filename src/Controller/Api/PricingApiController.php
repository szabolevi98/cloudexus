<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Model\Core\ProductModel;

class PricingApiController extends ApiController
{
    public function effective(): void
    {
        $this->authenticate();

        $productId = (int) ($_GET['product_id'] ?? 0);
        $partnerId = (int) ($_GET['partner_id'] ?? 0) ?: null;
        $quantity = (float) ($_GET['quantity'] ?? 1);
        $date = (string) ($_GET['date'] ?? '');

        if ($productId <= 0) {
            $this->error('The product_id parameter is required.', 422);
        }
        if ($quantity <= 0) {
            $this->error('quantity must be a positive number.', 422);
        }
        if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            $this->error('date must be YYYY-MM-DD.', 422);
        }

        $this->resource((new ProductModel())->effectivePrice($productId, $partnerId, $quantity, $date ?: null));
    }
}
