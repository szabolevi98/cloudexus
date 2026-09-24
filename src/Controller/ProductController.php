<?php

namespace Cloudexus\Controller;

use Cloudexus\Core\Auth;
use Cloudexus\Core\Paginator;
use Cloudexus\Core\Permissions;
use Cloudexus\Model\Core\CategoryModel;
use Cloudexus\Model\Core\CustomerGroupModel;
use Cloudexus\Model\Core\ProductModel;
use Cloudexus\Model\Core\StockMovementModel;
use Cloudexus\Model\Core\UnitModel;
use Cloudexus\Model\Core\WarehouseModel;

class ProductController extends BaseController
{
    private const UPLOAD_DIR = 'assets/uploads/products';
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private ProductModel $products;
    private CategoryModel $categories;
    private UnitModel $units;
    private CustomerGroupModel $customerGroups;

    public function __construct()
    {
        parent::__construct();
        $this->products = new ProductModel();
        $this->categories = new CategoryModel();
        $this->units = new UnitModel();
        $this->customerGroups = new CustomerGroupModel();
        $this->activeMenu = 'products';
    }

    public function list(): void
    {
        $this->requirePermission(Permissions::PRODUCTS_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'category_id' => (int) ($_GET['category_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
        ];
        $pager = new Paginator(30);

        $this->pageTitle = $this->t('products.list_title');
        $this->render('products/list.twig', [
            'products' => $this->products->paginate($filters, $pager),
            'pager' => $pager->toTwig($filters),
            'filters' => $filters,
            'category_option' => $filters['category_id'] ? $this->categories->labelsForIds([$filters['category_id']]) : [],
        ]);
    }

    public function export(): void
    {
        $this->requirePermission(Permissions::PRODUCTS_VIEW);

        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'category_id' => (int) ($_GET['category_id'] ?? 0),
            'status' => $_GET['status'] ?? '',
        ];
        $pager = new Paginator(1000000);
        $rows = $this->products->paginate($filters, $pager);

        \Cloudexus\Core\CsvExporter::download(
            'termekek',
            [
                $this->t('products.csv.sku'), $this->t('products.csv.barcode'), $this->t('products.csv.name'),
                $this->t('products.csv.category'), $this->t('products.csv.unit'), $this->t('products.csv.net_price'),
                $this->t('products.csv.vat'), $this->t('products.csv.stock'), $this->t('products.csv.min_stock'),
                $this->t('products.csv.webshop'), $this->t('products.csv.active'),
            ],
            array_map(fn($p) => [
                $p['sku'], $p['barcode'] ?? '', $p['name'], $p['category_name'] ?? '',
                $p['unit'], $p['price'], $p['vat_rate'], $p['stock_qty'], $p['min_stock'],
                $p['is_webshop'] ? $this->t('common.yes') : $this->t('common.no'),
                $p['is_active'] ? $this->t('common.yes') : $this->t('common.no'),
            ], $rows)
        );
    }

    public function search(): void
    {
        $this->requireAuth();
        $this->json($this->products->search(trim($_GET['q'] ?? ''), (int) ($_GET['page'] ?? 1)));
    }

    public function createForm(): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $this->pageTitle = $this->t('products.new');
        $this->render('products/form.twig', $this->formData(null));
    }

    public function create(): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data, null);

        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/products/create');
        }

        $id = $this->products->create($data);
        $this->handleImageUploads($id);
        $this->handleImageUrl($id);
        $this->handleOpeningStock($id);

        $this->flashSuccess($this->t('products.created'));
        $this->redirect('/products/' . $id . '/edit');
    }

    public function editForm(int $id): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $product = $this->products->findFull($id);
        if (!$product) {
            $this->redirect('/products');
        }

        $this->pageTitle = $this->t('products.edit_title');
        $this->render('products/form.twig', $this->formData($product));
    }

    public function update(int $id): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $data = $this->collectInput();
        $errors = $this->validate($data, $id);

        if ($errors) {
            $this->flashError(implode(' ', $errors));
            $this->redirect('/products/' . $id . '/edit');
        }

        $this->products->update($id, $data);
        $this->handleImageUploads($id);
        $this->handleImageUrl($id);

        $this->flashSuccess($this->t('products.updated'));
        $this->redirect('/products/' . $id . '/edit');
    }

    public function delete(int $id): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        try {
            $this->products->delete($id);
            $this->flashSuccess($this->t('products.deleted'));
        } catch (\PDOException $e) {
            // Van hozzá készletmozgás / bizonylat — ne töröljük, inkább inaktiváljuk.
            $this->flashError($this->t('products.delete_blocked'));
        }

        $this->redirect('/products');
    }

    public function deleteImage(int $id, int $imageId): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $image = $this->products->findImage($imageId);
        if ($image && (int) $image['product_id'] === $id) {
            $file = dirname(__DIR__, 2) . '/web/' . $image['path'];
            if (is_file($file)) {
                @unlink($file);
            }
            $this->products->deleteImage($imageId);
            $this->flashSuccess($this->t('products.image_deleted'));
        }

        $this->redirect('/products/' . $id . '/edit');
    }

    public function setPrimaryImage(int $id, int $imageId): void
    {
        $this->requirePermission(Permissions::PRODUCTS_MANAGE);

        $image = $this->products->findImage($imageId);
        if ($image && (int) $image['product_id'] === $id) {
            $this->products->setPrimaryImage($imageId);
            $this->flashSuccess($this->t('products.primary_set'));
        }

        $this->redirect('/products/' . $id . '/edit');
    }

    private function formData(?array $product): array
    {
        return [
            'product' => $product,
            'descriptions' => $product ? $this->products->descriptions((int) $product['id']) : [],
            'parameter_rows' => $product ? $this->products->parameterRows((int) $product['id']) : [],
            'units' => $this->units->all(),
            'warehouses' => (new WarehouseModel())->activeList(),
            'customer_groups' => $this->customerGroups->all(),
            // Előre kijelölt Select2 opciók (id + felirat) szerkesztéskor
            'category_options' => $product ? $this->categories->labelsForIds($product['category_ids']) : [],
            'related_options' => $product ? $this->products->labelsForIds($product['related_ids']) : [],
            'substitute_options' => $product ? $this->products->labelsForIds($product['substitute_ids']) : [],
        ];
    }

    private function collectInput(): array
    {
        // Nincs külön elsődleges kategória: a kiválasztott kategóriák elseje lesz
        // az elsődleges (category_id), a többi a kapcsolótáblába kerül.
        $categoryIds = array_values(array_filter(array_map('intval', $_POST['category_ids'] ?? [])));

        return [
            'sku' => trim($_POST['sku'] ?? ''),
            'barcode' => trim($_POST['barcode'] ?? ''),
            'name' => $this->localized('name'),
            'short_description' => $this->localized('short_description'),
            'description' => $this->localized('description'),
            'category_id' => $categoryIds[0] ?? 0,
            'category_ids' => $categoryIds,
            'unit_id' => ((int) ($_POST['unit_id'] ?? 0)) ?: null,
            'price' => (float) str_replace(',', '.', $_POST['price'] ?? '0'),
            'sale_price' => trim(str_replace(',', '.', $_POST['sale_price'] ?? '')),
            'vat_rate' => (float) str_replace(',', '.', $_POST['vat_rate'] ?? '27'),
            'min_stock' => (float) str_replace(',', '.', $_POST['min_stock'] ?? '0'),
            'width_mm' => $_POST['width_mm'] ?? '',
            'height_mm' => $_POST['height_mm'] ?? '',
            'depth_mm' => $_POST['depth_mm'] ?? '',
            'weight_g' => $_POST['weight_g'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'is_webshop' => isset($_POST['is_webshop']) ? 1 : 0,
            'parameter_id' => $_POST['parameter_id'] ?? [],
            'parameter_value' => (array) ($_POST['parameter_value'] ?? []),
            'related_ids' => $_POST['related_ids'] ?? [],
            'substitute_ids' => $_POST['substitute_ids'] ?? [],
            'group_id' => $_POST['group_id'] ?? [],
            'group_price' => $_POST['group_price'] ?? [],
            'group_sale_price' => $_POST['group_sale_price'] ?? [],
        ];
    }

    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        if ($data['sku'] === '' || $this->defaultText($data['name']) === '') {
            $errors[] = $this->t('products.required_fields');
        }
        if (!$errors && $this->products->skuExists($data['sku'], $excludeId)) {
            $errors[] = $this->t('products.sku_taken');
        }

        return $errors;
    }

    /** Saves any uploaded image files into web/assets/uploads/products and links them. */
    private function handleImageUploads(int $productId): void
    {
        if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
            return;
        }

        $uploadDir = dirname(__DIR__, 2) . '/web/' . self::UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        foreach ($_FILES['images']['name'] as $i => $name) {
            if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                $this->flashError($this->t('products.image_only'));
                continue;
            }
            if (($_FILES['images']['size'][$i] ?? 0) > 5 * 1024 * 1024) {
                $this->flashError($this->t('products.image_too_large'));
                continue;
            }

            $filename = 'prd-' . $productId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            $target = $uploadDir . '/' . $filename;

            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $target)) {
                $this->products->addImage($productId, self::UPLOAD_DIR . '/' . $filename);
            }
        }
    }

    /** Attaches an external image by URL (stored as-is; must be a valid http(s) URL). */
    private function handleImageUrl(int $productId): void
    {
        $url = trim($_POST['image_url'] ?? '');
        if ($url === '') {
            return;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
            $this->products->addImage($productId, $url);
        } else {
            $this->flashError($this->t('products.invalid_image_url'));
        }
    }

    private function handleOpeningStock(int $productId): void
    {
        $qty = (float) str_replace(',', '.', $_POST['opening_stock'] ?? '0');
        $warehouseId = (int) ($_POST['opening_warehouse_id'] ?? 0);

        if ($qty > 0 && $warehouseId > 0) {
            (new StockMovementModel())->create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'type' => 'in',
                'quantity' => $qty,
                'note' => 'Nyitókészlet',
                'created_by' => Auth::id(),
            ]);
        }
    }

    /**
     * Nyelvenkénti szövegek a POST-ból: name[<nyelv id>].
     *
     * @return array<int, string>
     */
    private function localized(string $field): array
    {
        $out = [];
        foreach ((array) ($_POST[$field] ?? []) as $languageId => $value) {
            $languageId = (int) $languageId;
            if ($languageId > 0) {
                $out[$languageId] = trim((string) $value);
            }
        }

        return $out;
    }

    /** A szöveg az alapnyelven — ez a kötelező kitöltés feltétele. */
    private function defaultText(array $localized): string
    {
        return $localized[\Cloudexus\Core\Language::defaultId()] ?? '';
    }
}
