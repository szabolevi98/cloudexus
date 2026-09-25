<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Acl;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Account\SavedFilterModel;

/**
 * Egy lista szűrése néven elmentve, és visszahozva egy kattintással. A
 * listák, ahol lehet — és a jog, ami a listához kell —, itt vannak felsorolva;
 * a lekérdezés-részt a szűrőűrlap mezőiből rakja össze, a lapozás nélkül.
 */
class SavedFilterController extends BaseController
{
    /** Lista => [cím, a jog, ami a látásához kell]. */
    public const PAGES = [
        'products' => ['/products', Permissions::PRODUCTS_VIEW],
        'partners' => ['/partners', Permissions::PARTNERS_VIEW],
        'orders' => ['/orders', Permissions::ORDERS_VIEW],
        'invoices' => ['/invoices', Permissions::INVOICES_VIEW],
        'purchase-orders' => ['/purchase-orders', Permissions::PURCHASING_VIEW],
        'incoming-invoices' => ['/incoming-invoices', Permissions::PURCHASING_VIEW],
        'cash' => ['/cash', Permissions::CASH_VIEW],
        'audit' => ['/audit', Permissions::AUDIT_VIEW],
    ];

    public function create(): void
    {
        $page = (string) ($_POST['page'] ?? '');
        $path = $this->pathFor($page);

        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80));
        parse_str((string) ($_POST['query'] ?? ''), $params);
        unset($params['page'], $params['_token']);
        $query = http_build_query(array_filter($params, static fn($value): bool => $value !== '' && $value !== []));

        if ($name === '') {
            $this->flashError($this->t('saved_filters.name_required'));
            $this->redirect($path . ($query !== '' ? '?' . $query : ''));
        }

        (new SavedFilterModel())->create((int) Auth::id(), $page, $name, $query, ($_POST['is_shared'] ?? '') === '1');
        $this->flashSuccess($this->t('saved_filters.saved', ['name' => $name]));
        $this->redirect($path . ($query !== '' ? '?' . $query : ''));
    }

    public function delete(int $id): void
    {
        $this->requireAuth();

        $models = new SavedFilterModel();
        $filter = $models->find($id);
        if ($filter === null) {
            $this->redirect('/dashboard');
        }
        $path = $this->pathFor((string) $filter['page']);

        // A sajátját bárki, a másét csak a beállítások kezelője törölheti.
        if ((int) $filter['user_id'] === Auth::id() || Acl::can(Permissions::SETTINGS_MANAGE)) {
            $models->delete($id);
            $this->flashSuccess($this->t('saved_filters.deleted', ['name' => $filter['name']]));
        }
        $this->redirect($path);
    }

    private function pathFor(string $page): string
    {
        $this->requireAuth();
        if (!isset(self::PAGES[$page])) {
            $this->redirect('/dashboard');
        }
        $this->requirePermission(self::PAGES[$page][1]);

        return self::PAGES[$page][0];
    }
}
