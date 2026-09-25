<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Currency;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\CustomerGroupModel;
use Cloudexus\Model\Core\PriceRuleModel;

/**
 * Árszabályok kezelése. A lista a termékeket látóknak is nyitva van, hogy
 * az értékesítő lássa, mi miért kerül annyiba; szerkeszteni a pricing.manage
 * joggal lehet.
 */
class PriceRuleController extends BaseController
{
    private PriceRuleModel $rules;

    public function __construct()
    {
        parent::__construct();
        $this->rules = new PriceRuleModel();
        $this->activeMenu = 'price-rules';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::PRODUCTS_VIEW);

        $status = (string) ($_GET['status'] ?? '');
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => in_array($status, ['active', 'scheduled', 'expired', 'inactive'], true) ? $status : '',
        ];
        $pager = new Paginator(25);

        $this->pageTitle = $this->t('price_rules.list_title');
        $this->render('price-rules/list.twig', [
            'rules' => $this->rules->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'today' => date('Y-m-d'),
        ]);
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::PRICING_MANAGE);

        $this->pageTitle = $this->t('price_rules.new');
        $this->render('price-rules/form.twig', ['rule' => null, 'groups' => (new CustomerGroupModel())->all()]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::PRICING_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data);
        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/price-rules/create');
        }

        $id = $this->rules->create($data);
        AuditLog::record(AuditLog::CREATE, 'price_rule', $id, $data['name'], ['effect' => $this->effect($data)]);

        $this->flashSuccess($this->t('price_rules.created'));
        $this->redirect('/price-rules');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::PRICING_MANAGE);

        $rule = $this->rules->findById($id);
        if (!$rule) {
            $this->redirect('/price-rules');
        }

        $this->pageTitle = $this->t('price_rules.edit');
        $this->render('price-rules/form.twig', ['rule' => $rule, 'groups' => (new CustomerGroupModel())->all()]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::PRICING_MANAGE);

        $before = $this->rules->findById($id);
        if (!$before) {
            $this->redirect('/price-rules');
        }

        $data = $this->collectInput();
        $errors = $this->validate($data);
        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/price-rules/' . $id . '/edit');
        }

        $this->rules->update($id, $data);
        $old = $this->effect($before);
        $new = $this->effect($data);
        AuditLog::record(AuditLog::UPDATE, 'price_rule', $id, $data['name'], $old !== $new ? ['effect' => [$old, $new]] : null);

        $this->flashSuccess($this->t('price_rules.updated'));
        $this->redirect('/price-rules');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::PRICING_MANAGE);

        $rule = $this->rules->findById($id);
        if ($rule) {
            \Cloudexus\Core\Undo::capture($this->t('undo.price_rule', ['name' => $rule['name']]), '/price-rules', [['price_rules', 'id', $id]]);
            $this->rules->delete($id);
            AuditLog::record(AuditLog::DELETE, 'price_rule', $id, $rule['name']);
            $this->flashSuccess($this->t('price_rules.deleted'));
        }
        $this->redirect('/price-rules');
    }

    private function collectInput(): array
    {
        $number = static fn(string $key): ?float => trim((string) ($_POST[$key] ?? '')) === ''
            ? null
            : (float) str_replace([',', ' '], ['.', ''], (string) $_POST[$key]);
        $date = static fn(string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$key] ?? '')) ? $_POST[$key] : null;
        $isFixed = ($_POST['effect'] ?? 'percent') === 'fixed';
        $scope = (string) ($_POST['scope'] ?? 'all');

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'customer_group_id' => (int) ($_POST['customer_group_id'] ?? 0),
            'product_id' => $scope === 'product' ? (int) ($_POST['product_id'] ?? 0) : 0,
            'category_id' => $scope === 'category' ? (int) ($_POST['category_id'] ?? 0) : 0,
            'scope' => $scope,
            'min_quantity' => max(0, $number('min_quantity') ?? 0),
            'discount_percent' => $isFixed ? null : $number('discount_percent'),
            'fixed_price' => $isFixed ? $number('fixed_price') : null,
            'valid_from' => $date('valid_from'),
            'valid_to' => $date('valid_to'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors[] = $this->t('price_rules.name_required');
        }
        if ($data['scope'] === 'product' && !$data['product_id']) {
            $errors[] = $this->t('price_rules.product_required');
        }
        if ($data['scope'] === 'category' && !$data['category_id']) {
            $errors[] = $this->t('price_rules.category_required');
        }
        if ($data['fixed_price'] === null && ($data['discount_percent'] === null || $data['discount_percent'] <= 0 || $data['discount_percent'] >= 100)) {
            $errors[] = $this->t('price_rules.percent_range');
        }
        if ($data['discount_percent'] === null && ($data['fixed_price'] === null || $data['fixed_price'] < 0)) {
            $errors[] = $this->t('price_rules.fixed_required');
        }
        if ($data['valid_from'] && $data['valid_to'] && $data['valid_to'] < $data['valid_from']) {
            $errors[] = $this->t('price_rules.dates_order');
        }

        return $errors;
    }

    /** "−10%" vagy "4 990 Ft" — a naplóba. */
    private function effect(array $rule): string
    {
        return $rule['fixed_price'] !== null
            ? Currency::format((float) $rule['fixed_price'])
            : '−' . rtrim(rtrim(number_format((float) $rule['discount_percent'], 2, ',', ''), '0'), ',') . '%';
    }
}
