<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\AuditLog;
use Cloudexus\Core\Auth;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\CustomerGroupModel;
use Cloudexus\Model\Core\PartnerAddressModel;
use Cloudexus\Model\Core\PartnerModel;
use Cloudexus\Model\Crm\PartnerActivityModel;

class PartnerController extends BaseController
{
    private PartnerModel $partners;
    private PartnerActivityModel $activities;
    private PartnerAddressModel $addresses;
    private CustomerGroupModel $customerGroups;

    public function __construct()
    {
        parent::__construct();
        $this->partners = new PartnerModel();
        $this->activities = new PartnerActivityModel();
        $this->addresses = new PartnerAddressModel();
        $this->customerGroups = new CustomerGroupModel();
        $this->activeMenu = 'partners';
    }

    /** Select2 AJAX endpoint. ?role=customer|supplier szűkíthet a partner típusára. */
    public function search(): void
    {
        $this->requireAuth();

        $role = $_GET['role'] ?? '';
        $role = in_array($role, ['customer', 'supplier'], true) ? $role : null;

        $this->json($this->partners->search(trim($_GET['q'] ?? ''), (int) ($_GET['page'] ?? 1), 20, $role));
    }

    public function show(int $id): void
    {
        $this->requirePermission(Permissions::PARTNERS_VIEW);

        $partner = $this->partners->findById($id);
        if (!$partner) {
            $this->redirect('/partners');
        }

        $this->pageTitle = $partner['name'];
        $this->remember('partner', $id);
        $overview = new \Cloudexus\Model\Crm\PartnerOverviewModel();
        $this->render('partners/show.twig', [
            'partner' => $partner,
            'activities' => $this->activities->forPartner($id),
            'addresses' => $this->addresses->forPartner($id),
            'contacts' => (new \Cloudexus\Model\Core\PartnerContactModel())->forPartner($id),
            'kpis' => $overview->kpis($id),
            'timeline' => $overview->timeline($id),
            'top_products' => $overview->topProducts($id),
            'deals' => \Cloudexus\Core\Acl::can(Permissions::CRM_VIEW) ? (new \Cloudexus\Model\Crm\DealModel())->forPartner($id) : null,
        ]);
    }

    public function addAddress(int $id): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        if (!$this->partners->findById($id)) {
            $this->redirect('/partners');
        }

        $city = trim($_POST['city'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $street = trim($_POST['street'] ?? '');

        if ($city === '' || $postalCode === '' || $street === '') {
            $this->flashError($this->t('partners.address_fields_required'));
            $this->redirect('/partners/' . $id);
        }

        $this->addresses->create([
            'partner_id' => $id,
            'country' => trim($_POST['country'] ?? ''),
            'city' => $city,
            'postal_code' => $postalCode,
            'street' => $street,
            'note' => trim($_POST['note'] ?? ''),
        ]);

        $this->flashSuccess($this->t('partners.address_added'));
        $this->redirect('/partners/' . $id);
    }

    public function updateAddress(int $id, int $addressId): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $address = $this->addresses->findById($addressId);
        if (!$address || (int) $address['partner_id'] !== $id) {
            $this->redirect('/partners/' . $id);
        }

        $city = trim($_POST['city'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $street = trim($_POST['street'] ?? '');

        if ($city === '' || $postalCode === '' || $street === '') {
            $this->flashError($this->t('partners.address_fields_required'));
            $this->redirect('/partners/' . $id);
        }

        $this->addresses->update($addressId, [
            'country' => trim($_POST['country'] ?? ''),
            'city' => $city,
            'postal_code' => $postalCode,
            'street' => $street,
            'note' => trim($_POST['note'] ?? ''),
        ]);

        $this->flashSuccess($this->t('partners.address_updated'));
        $this->redirect('/partners/' . $id);
    }

    public function deleteAddress(int $id, int $addressId): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $address = $this->addresses->findById($addressId);
        if ($address && (int) $address['partner_id'] === $id) {
            $this->addresses->delete($addressId);
            $this->flashSuccess($this->t('partners.address_deleted'));
        }

        $this->redirect('/partners/' . $id);
    }

    public function addActivity(int $id): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        if (!$this->partners->findById($id)) {
            $this->redirect('/partners');
        }

        $subject = trim($_POST['subject'] ?? '');
        if ($subject === '') {
            $this->flashError($this->t('partners.activity_subject_required'));
            $this->redirect('/partners/' . $id);
        }

        $this->activities->create([
            'partner_id' => $id,
            'contact_id' => $this->ownContact($id, (int) ($_POST['contact_id'] ?? 0)),
            'type' => in_array($_POST['type'] ?? '', ['call', 'email', 'meeting', 'note', 'offer'], true) ? $_POST['type'] : 'note',
            'subject' => $subject,
            'note' => trim($_POST['note'] ?? ''),
            'activity_date' => ($_POST['activity_date'] ?? '') !== '' ? str_replace('T', ' ', $_POST['activity_date']) . ':00' : date('Y-m-d H:i:s'),
            'created_by' => Auth::id(),
        ]);

        $this->flashSuccess($this->t('partners.activity_added'));
        $this->redirect('/partners/' . $id);
    }

    public function deleteActivity(int $id, int $activityId): void
    {
        $this->requirePermission(Permissions::CRM_MANAGE);

        $activity = $this->activities->findById($activityId);
        if ($activity && (int) $activity['partner_id'] === $id) {
            $this->activities->delete($activityId);
            $this->flashSuccess($this->t('partners.activity_deleted'));
        }

        $this->redirect('/partners/' . $id);
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::PARTNERS_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'type' => $_GET['type'] ?? '',
            'status' => $_GET['status'] ?? '',
            'customer_group_id' => (int) ($_GET['customer_group_id'] ?? 0),
        ];
        $pager = new \Cloudexus\Core\Paginator(25);

        $this->pageTitle = $this->t('partners.list_title');
        $this->render('partners/list.twig', [
            'partners' => $this->partners->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'customer_groups' => $this->customerGroups->all(),
        ]);
    }

    public function export(): void
    {
        $this->requirePermission(Permissions::PARTNERS_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'type' => $_GET['type'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];
        $pager = new \Cloudexus\Core\Paginator(1000000);
        $rows = $this->partners->paginate($filters, $pager);

        $typeLabels = [
            'customer' => $this->t('partners.csv_type.customer'),
            'supplier' => $this->t('partners.csv_type.supplier'),
            'both' => $this->t('partners.csv_type.both'),
        ];

        \Cloudexus\Core\CsvExporter::download(
            'partnerek',
            [
                $this->t('partners.csv.name'), $this->t('partners.csv.type'), $this->t('partners.csv.tax_number'),
                $this->t('partners.csv.email'), $this->t('partners.csv.phone'), $this->t('partners.csv.address'),
                $this->t('partners.csv.active'),
            ],
            array_map(fn($p) => [
                $p['name'], $typeLabels[$p['type']] ?? $p['type'], $p['tax_number'] ?? '',
                $p['email'] ?? '', $p['phone'] ?? '', $p['address'] ?? '',
                $p['is_active'] ? $this->t('common.yes') : $this->t('common.no'),
            ], $rows)
        );
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $this->pageTitle = $this->t('partners.new');
        $this->render('partners/form.twig', ['partner' => null, 'customer_groups' => $this->customerGroups->all()]);
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $data = $this->collectInput();

        if ($data['name'] === '') {
            $this->flashError($this->t('partners.name_required'));
            $this->redirect('/partners/create');
        }

        $this->partners->create($data);
        $this->flashSuccess($this->t('partners.created'));
        $this->redirect('/partners');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $partner = $this->partners->findById($id);
        if (!$partner) {
            $this->redirect('/partners');
        }

        $this->pageTitle = $this->t('partners.edit_title');
        $this->render('partners/form.twig', [
            'partner' => $partner,
            'customer_groups' => $this->customerGroups->all(),
            'addresses' => $this->addresses->forPartner($id),
        ]);
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $data = $this->collectInput();

        if ($data['name'] === '') {
            $this->flashError($this->t('partners.name_required'));
            $this->redirect('/partners/' . $id . '/edit');
        }

        $this->partners->update($id, $data);
        $this->flashSuccess($this->t('partners.updated'));
        $this->redirect('/partners');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $partner = $this->partners->findById($id);
        if ($partner) {
            \Cloudexus\Core\Undo::capture($this->t('undo.partner', ['name' => $partner['name']]), '/partners/' . $id, [
                ['partners', 'id', $id],
                ['partner_addresses', 'partner_id', $id],
                ['partner_contacts', 'partner_id', $id],
                ['partner_activities', 'partner_id', $id],
            ], [
                ['todos', 'partner_id', $id],
                ['cash_vouchers', 'partner_id', $id],
            ]);
        }

        try {
            $this->partners->delete($id);
            $this->flashSuccess($this->t('partners.deleted'));
        } catch (\PDOException $e) {
            // Van rendelése, számlája: nem törölhető, csak inaktiválható.
            \Cloudexus\Core\Undo::forget();
            $this->flashError($this->t('partners.delete_blocked'));
        }
        $this->redirect('/partners');
    }

    private function collectInput(): array
    {
        return [
            'type' => $_POST['type'] ?? 'customer',
            'customer_group_id' => (int) ($_POST['customer_group_id'] ?? 0),
            'name' => trim($_POST['name'] ?? ''),
            'tax_number' => trim($_POST['tax_number'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'credit_limit' => trim((string) ($_POST['credit_limit'] ?? '')) === '' ? null : max(0, (float) str_replace([' ', ','], ['', '.'], (string) $_POST['credit_limit'])),
            'payment_terms_days' => trim((string) ($_POST['payment_terms_days'] ?? '')) === '' ? null : max(0, min(365, (int) $_POST['payment_terms_days'])),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    /** A kipipált partnerek egyszerre: aktiválás, inaktiválás, vevőcsoport. */
    public function bulk(): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
        $action = (string) ($_POST['action'] ?? '');
        $back = '/partners' . (($_POST['back'] ?? '') !== '' ? '?' . $_POST['back'] : '');
        [$column, $value] = match ($action) {
            'activate' => ['is_active', 1],
            'deactivate' => ['is_active', 0],
            'group' => ['customer_group_id', (int) ($_POST['customer_group_id'] ?? 0) ?: null],
            default => [null, null],
        };

        if ($ids === [] || $column === null) {
            $this->flashError($this->t('bulk.nothing'));
            $this->redirect($back);
        }

        $this->partners->bulkSet($ids, $column, $value);
        AuditLog::record(AuditLog::UPDATE, 'partner', null, $this->t('bulk.audit_label', ['count' => count($ids)]), ['bulk' => $this->t('bulk.' . ($action === 'group' ? 'set_group' : $action))]);
        $this->flashSuccess($this->t('bulk.done', ['count' => count($ids)]));
        $this->redirect($back);
    }

    /** Egy kapcsolattartó felvétele vagy átírása a partner oldaláról. */
    public function saveContact(int $id, ?int $contactId = null): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $partner = $this->partners->findById($id);
        if (!$partner) {
            $this->redirect('/partners');
        }
        $contacts = new \Cloudexus\Model\Core\PartnerContactModel();
        if ($contactId !== null && (int) ($contacts->find($contactId)['partner_id'] ?? 0) !== $id) {
            $this->redirect('/partners/' . $id);
        }

        $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 120));
        $email = trim(mb_substr((string) ($_POST['email'] ?? ''), 0, 190));
        if ($name === '') {
            $this->flashError($this->t('contacts.name_required'));
            $this->redirect('/partners/' . $id);
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flashError($this->t('contacts.email_invalid'));
            $this->redirect('/partners/' . $id);
        }

        $contacts->save($id, $contactId, [
            'name' => $name,
            'position' => trim(mb_substr((string) ($_POST['position'] ?? ''), 0, 120)),
            'email' => $email,
            'phone' => trim(mb_substr((string) ($_POST['phone'] ?? ''), 0, 40)),
            'note' => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 255)),
            'is_primary' => ($_POST['is_primary'] ?? '') === '1',
            'receives_invoices' => ($_POST['receives_invoices'] ?? '') === '1',
        ]);
        AuditLog::record($contactId === null ? AuditLog::CREATE : AuditLog::UPDATE, 'partner', $id, (string) $partner['name'], ['contact' => $name]);

        $this->flashSuccess($this->t('contacts.saved', ['name' => $name]));
        $this->redirect('/partners/' . $id);
    }

    public function updateContact(int $id, int $contactId): void
    {
        $this->saveContact($id, $contactId);
    }

    public function deleteContact(int $id, int $contactId): void
    {
        $this->requirePermission(Permissions::PARTNERS_MANAGE);

        $contacts = new \Cloudexus\Model\Core\PartnerContactModel();
        $contact = $contacts->find($contactId);
        if ($contact !== null && (int) $contact['partner_id'] === $id) {
            $contacts->delete($id, $contactId);
            $this->flashSuccess($this->t('contacts.deleted', ['name' => $contact['name']]));
        }
        $this->redirect('/partners/' . $id);
    }

    /** A kapcsolattartó azonosítója, ha tényleg ennek a partnernek a kapcsolattartója, különben null. */
    private function ownContact(int $partnerId, int $contactId): ?int
    {
        if ($contactId <= 0) {
            return null;
        }
        $contact = (new \Cloudexus\Model\Core\PartnerContactModel())->find($contactId);

        return $contact !== null && (int) $contact['partner_id'] === $partnerId ? $contactId : null;
    }

    /**
     * A partner hitelkerete és tartozása JSON-ben — a rendelés, az ajánlat és
     * a számla űrlapja ebből figyelmeztet, és ebből veszi a fizetési
     * határidőt.
     */
    public function credit(int $id): void
    {
        $this->requireAnyPermission(Permissions::PARTNERS_VIEW, Permissions::ORDERS_VIEW, Permissions::INVOICES_VIEW);

        $this->json((new \Cloudexus\Model\Crm\PartnerOverviewModel())->credit($id));
    }
}
