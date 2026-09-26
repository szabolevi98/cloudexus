<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Purchasing\IncomingInvoiceModel;
use Cloudexus\Model\Sales\InvoiceModel;
use Cloudexus\Model\Sales\OrderModel;

class DashboardController extends BaseController
{
    public function show(): void
    {
        $this->requirePermission(Permissions::DASHBOARD_VIEW);

        $orders = new OrderModel();
        $invoices = new InvoiceModel();

        $dailyOrders = $orders->dailyTotals(10);
        $topCategories = $orders->topCategories(30, 10);

        $this->activeMenu = 'dashboard';
        $this->pageTitle = $this->t('dashboard.title');
        $this->render('dashboard.twig', [
            'product_count' => (new ProductModel())->count(),
            'outstanding' => $invoices->outstandingBreakdown(),
            'payable' => (new IncomingInvoiceModel())->outstandingBreakdown(),
            'daily_orders' => $dailyOrders,
            'orders_total_value' => array_sum(array_column($dailyOrders, 'total_value')),
            'top_categories' => $topCategories,
            'top_categories_max' => $topCategories ? max(array_column($topCategories, 'value')) : 0,
            'recent_invoices' => $invoices->recent(6),
            'low_stock' => (new ProductModel())->lowStock(8),
            'open_todos' => (new \Cloudexus\Model\Crm\TodoModel())->mine((int) \Cloudexus\Core\Auth::id(), 6),
            'open_todo_count' => (new \Cloudexus\Model\Crm\TodoModel())->mineCount((int) \Cloudexus\Core\Auth::id()),
            'today' => date('Y-m-d'),
        ]);
    }
}
