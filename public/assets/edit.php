<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\ReferenceDataService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canManageAssets()) {
    http_response_code(403);
    exit('You do not have permission to edit assets.');
}

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$id || $id <= 0) {
    http_response_code(400);
    exit('Invalid asset ID.');
}

$assetService = new AssetService();
$referenceDataService = new ReferenceDataService();

try {
    $asset = $assetService->findById((int) $id);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Unable to load asset.');
}

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$categories = $referenceDataService->getAssetCategories();
$statuses = $referenceDataService->getAssetStatuses();
$conditions = $referenceDataService->getAssetConditions();
$vendors = $referenceDataService->getVendors();
$departments = $referenceDataService->getDepartments();

/*
 * Determine whether this asset already has an assignee.
 */
$isCurrentlyAssigned = !empty($asset['assigned_to_user_id'])
    || trim((string) ($asset['assigned_to_name'] ?? '')) !== '';

/*
 * Hide Assigned from the status dropdown when the asset
 * has no existing assignment.
 *
 * The dedicated Assign Asset workflow handles new assignments.
 */
if (!$isCurrentlyAssigned) {
    $statuses = array_values(
        array_filter(
            $statuses,
            static fn(array $status): bool =>
                strcasecmp(
                    trim((string) $status['name']),
                    'Assigned'
                ) !== 0
        )
    );
}

$pageTitle = 'Edit Asset';
$pageStylesheet = 'css/edit.css';
$pageScript = 'js/edit.js';

require_once dirname(__DIR__) . '/includes/app_header.php';

$currentStatus = strtolower(
    str_replace(
        ' ',
        '-',
        (string) ($asset['status_name'] ?? '')
    )
);

$workbookValue = static function (mixed $value): string {
    if ($value === null || trim((string) $value) === '' || strtolower(trim((string) $value)) === 'null') {
        return '—';
    }

    return (string) $value;
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><div class="text-muted small mb-1">Asset Management / Edit</div><h1 class="h3 mb-1">Edit Asset</h1><p class="text-muted mb-0"><?= e($asset['asset_code'] ?? '') ?> — <?= e($asset['asset_name'] ?? '') ?></p></div>
    <a href="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Asset</a>
</div>
<form id="assetEditForm" method="post" action="<?= e(base_url('/public/assets/edit_process.php')) ?>" data-view-url="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>" novalidate>
<?= \App\Helpers\Csrf::field() ?>
<input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0"><i class="fa-solid fa-clipboard-list text-teal me-2"></i>Official Asset Information</h2><p class="text-muted small mb-0 mt-1">Update the official inventory details below. The AssetSys tracking code remains fixed.</p></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">AssetSys Asset Code</label><input class="form-control" value="<?= e($asset['asset_code'] ?? '') ?>" readonly><div class="form-text">System-generated identifier used by AssetSys and QR labels.</div></div>
        <div class="col-md-6"><label class="form-label" for="finance_main_location">1. Location (Main)</label><input class="form-control" id="finance_main_location" name="finance_main_location" maxlength="150" value="<?= e($asset['finance_main_location'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="finance_owner_department">2. Owner / Department</label><input class="form-control" id="finance_owner_department" name="finance_owner_department" maxlength="150" list="departmentOptions" value="<?= e($asset['finance_owner_department'] ?? $asset['department_name'] ?? '') ?>"><datalist id="departmentOptions"><?php foreach ($departments as $department): ?><option value="<?= e($department['name']) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-md-6"><label class="form-label" for="category_id">3. Category <span class="text-danger">*</span></label><select class="form-select" id="category_id" name="category_id" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $asset['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="finance_item_id">4. Item ID</label><input class="form-control" id="finance_item_id" name="finance_item_id" maxlength="100" value="<?= e($asset['finance_item_id'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="asset_name">5. Item Name <span class="text-danger">*</span></label><input class="form-control" id="asset_name" name="asset_name" maxlength="200" value="<?= e($asset['asset_name'] ?? '') ?>" required><input type="hidden" id="finance_item_name" name="finance_item_name" value="<?= e($asset['finance_item_name'] ?? $asset['asset_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="finance_ffe_number">6. FFE Number</label><input class="form-control" id="finance_ffe_number" name="finance_ffe_number" maxlength="150" value="<?= e($asset['finance_ffe_number'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="finance_code">7. Code</label><input class="form-control" id="finance_code" name="finance_code" maxlength="100" value="<?= e($asset['finance_code'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="status_id">8. Status <span class="text-danger">*</span></label><select class="form-select" id="status_id" name="status_id" required><option value="">Select status</option><?php foreach ($statuses as $status): ?><option value="<?= (int) $status['id'] ?>" <?= (int) $asset['status_id'] === (int) $status['id'] ? 'selected' : '' ?>><?= e($status['name']) ?></option><?php endforeach; ?></select><input type="hidden" id="finance_status" name="finance_status" value="<?= e($asset['finance_status'] ?? $asset['status_name'] ?? '') ?>"><div class="form-text">Assignment and lifecycle restrictions still apply.</div></div>
        <div class="col-md-6"><label class="form-label" for="finance_supplier">9. Supplier</label><input class="form-control" id="finance_supplier" name="finance_supplier" maxlength="150" value="<?= e($asset['finance_supplier'] ?? $asset['supplier_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="finance_po_number">10. PO Number</label><input class="form-control" id="finance_po_number" name="finance_po_number" maxlength="100" value="<?= e($asset['finance_po_number'] ?? $asset['purchase_order_number'] ?? '') ?>"><input type="hidden" name="purchase_order_number" value="<?= e($asset['purchase_order_number'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="finance_price_text">11. Price</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" type="text" id="finance_price_text" name="finance_price_text" inputmode="decimal" value="<?= e($asset['finance_price_text'] ?? ($asset['finance_price'] ?? $asset['purchase_cost'] ?? '')) ?>" placeholder="Enter price or source text"></div><input type="hidden" id="finance_price" name="finance_price" value="<?= e($asset['finance_price'] ?? '') ?>"><input type="hidden" id="purchase_cost" name="purchase_cost" value="<?= e($asset['purchase_cost'] ?? '0.00') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="purchase_date">12. Date Purchase</label><input class="form-control" type="date" id="purchase_date" name="purchase_date" value="<?= e($asset['purchase_date'] ?? '') ?>"><input type="hidden" id="finance_date_purchase_text" name="finance_date_purchase_text" value="<?= e($asset['finance_date_purchase_text'] ?? '') ?>"></div>
    </div></div>
</div>
<details class="card border-0 shadow-sm mb-4">
    <summary class="card-header bg-white py-3 fw-semibold" style="cursor:pointer"><i class="fa-solid fa-sliders text-teal me-2"></i>Operational Details <span class="text-muted fw-normal small">(managed by AssetSys workflows)</span></summary>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><div class="form-label">Current Physical Location</div><p class="form-control-plaintext"><?= e($asset['location_name'] ?? 'Not set') ?></p></div>
            <div class="col-md-6"><div class="form-label">Assigned Employee</div><p class="form-control-plaintext"><?= e(trim((string) ($asset['assigned_to_name'] ?? '')) ?: 'Unassigned') ?></p></div>
            <div class="col-md-6"><div class="form-label">Operational Department</div><p class="form-control-plaintext"><?= e($asset['department_name'] ?? 'Not set') ?></p></div>
            <div class="col-md-6"><div class="form-label">Condition</div><p class="form-control-plaintext"><?= e($asset['condition_name'] ?? 'Not set') ?></p></div>
        </div>
        <p class="form-text mb-0">Change physical location, department ownership, assignment, and condition through their dedicated AssetSys workflows so movement history and controls remain intact.</p>
    </div>
</details>
<details class="card border-0 shadow-sm mb-4">
    <summary class="card-header bg-white py-3 fw-semibold" style="cursor:pointer"><i class="fa-solid fa-file-lines text-teal me-2"></i>Additional Information <span class="text-muted fw-normal small">(optional)</span></summary>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="asset_tag">Asset Tag</label><input class="form-control" id="asset_tag" name="asset_tag" maxlength="100" value="<?= e($asset['asset_tag'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="serial_number">Serial Number</label><input class="form-control" id="serial_number" name="serial_number" maxlength="150" value="<?= e($asset['serial_number'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="manufacturer">Manufacturer</label><input class="form-control" id="manufacturer" name="manufacturer" maxlength="150" value="<?= e($asset['manufacturer'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="model">Model</label><input class="form-control" id="model" name="model" maxlength="150" value="<?= e($asset['model'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="model_number">Model Number</label><input class="form-control" id="model_number" name="model_number" maxlength="150" value="<?= e($asset['model_number'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="invoice_number">Invoice Number</label><input class="form-control" id="invoice_number" name="invoice_number" maxlength="100" value="<?= e($asset['invoice_number'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="supplier_id">Linked Supplier Record</label><select class="form-select" id="supplier_id" name="supplier_id"><option value="">Not linked</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int) $vendor['id'] ?>" <?= (int) ($asset['supplier_id'] ?? 0) === (int) $vendor['id'] ? 'selected' : '' ?>><?= e($vendor['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="warranty_start">Warranty Start</label><input class="form-control" type="date" id="warranty_start" name="warranty_start" value="<?= e($asset['warranty_start'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="warranty_end">Warranty End</label><input class="form-control" type="date" id="warranty_end" name="warranty_end" value="<?= e($asset['warranty_end'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="useful_life_years">Useful Life (Years) (optional)</label><input class="form-control" type="number" id="useful_life_years" name="useful_life_years" min="0.01" step="0.01" value="<?= e($asset['useful_life_years'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="salvage_value">Salvage Value</label><input class="form-control" type="number" id="salvage_value" name="salvage_value" min="0" step="0.01" value="<?= e($asset['salvage_value'] ?? '0') ?>"></div>
        <div class="col-12"><p class="form-text mb-0">Useful life is optional. Leave it blank when it is unknown or the asset is not being depreciated. A depreciation schedule requires a valid useful life. Salvage value defaults to zero.</p></div>
        <div class="col-md-4"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="2"><?= e($asset['description'] ?? '') ?></textarea></div>
        <input type="hidden" name="depreciation_method" value="<?= e($asset['depreciation_method'] ?? 'straight_line') ?>">
    </div></div>
</details>
<div class="d-flex justify-content-end gap-2 mb-5"><a href="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-primary" id="saveAssetButton"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assetEditForm');
    const name = document.getElementById('asset_name');
    const financeName = document.getElementById('finance_item_name');
    const status = document.getElementById('status_id');
    const financeStatus = document.getElementById('finance_status');
    const priceText = document.getElementById('finance_price_text');
    const price = document.getElementById('finance_price');
    const purchaseCost = document.getElementById('purchase_cost');
    const date = document.getElementById('purchase_date');
    const dateText = document.getElementById('finance_date_purchase_text');
    function sync() {
        financeName.value = name.value;
        financeStatus.value = status.selectedIndex > 0 ? status.options[status.selectedIndex].text.trim() : '';
        const raw = priceText.value.trim().replace(/[₱,\s]/g, '').replace(/^PHP/i, '');
        const numeric = raw !== '' && raw !== '-' && raw.toUpperCase() !== 'WARRANTY' && /^\d+(\.\d{1,2})?$/.test(raw);
        price.value = numeric ? Number(raw).toFixed(2) : '';
        purchaseCost.value = numeric ? Number(raw).toFixed(2) : '0.00';
        if (date.value) dateText.value = date.value;
    }
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('submit', sync);
    sync();
});
</script>
<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>