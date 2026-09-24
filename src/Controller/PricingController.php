<?php

namespace Cloudexus\Controller;

use Cloudexus\Model\Core\ProductModel;

/**
 * AJAX endpoint the line-item picker calls to resolve the price to prefill:
 * on sales forms the partner's group price, sale price and price rules for
 * the quantity and the document date (ProductModel::effectivePrice); on
 * purchase forms (base=1) only the product's own price.
 */
class PricingController extends BaseController
{
    public function effective(): void
    {
        $this->requireAuth();

        $productId = (int) ($_GET['product_id'] ?? 0);
        $partnerId = (int) ($_GET['partner_id'] ?? 0) ?: null;
        $quantity = (float) str_replace(',', '.', (string) ($_GET['quantity'] ?? '1'));
        $date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) ($_GET['date'] ?? ''), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? $_GET['date'] : null;
        $withRules = ($_GET['base'] ?? '') !== '1';

        if ($productId <= 0) {
            $this->json(['price' => 0, 'is_sale' => false, 'list_price' => 0, 'rule' => null]);
        }

        $this->json((new ProductModel())->effectivePrice($productId, $withRules ? $partnerId : null, $quantity ?: 1, $date, $withRules));
    }
}
