<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Model\Core\LocationModel;
use Cloudexus\Model\Core\WarehouseModel;

class WarehouseApiController extends ApiController
{
    public function index(): void
    {
        $this->authenticate();

        $filters = $this->baseFilters() + ['status' => $_GET['status'] ?? ''];
        $pager = $this->paginator();
        $rows = (new WarehouseModel())->paginate($filters, $pager);

        $this->collection($rows, $pager);
    }

    /** A warehouse's storage locations (shelves), with the stock held on each. */
    public function locations(int $id): void
    {
        $this->authenticate();

        if (!(new WarehouseModel())->findById($id)) {
            $this->error('Warehouse not found.', 404);
        }

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'code' => trim($_GET['code'] ?? ''),
            'warehouse_id' => $id,
            'status' => $_GET['status'] ?? '',
        ];
        $pager = $this->paginator();
        $rows = (new LocationModel())->paginate($filters, $pager);

        $this->collection($rows, $pager);
    }
}
