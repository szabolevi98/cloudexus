<?php

namespace Cloudexus\Controller;

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
        $this->render('partners/show.twig', [
            'partner' => $partner,
            'activities' => $this->activities->forPartner($id),
            'addresses' => $this->addresses->forPartner($id),
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

        $this->partners->delete($id);
        $this->flashSuccess($this->t('partners.deleted'));
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
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }
}
