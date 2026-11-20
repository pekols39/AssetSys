<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\ReferenceDataService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to create assets.');
}

$referenceDataService = new ReferenceDataService();

$categories = $referenceDataService->getAssetCategories();
$statuses = $referenceDataService->getAssetStatuses();
$conditions = $referenceDataService->getAssetConditions();
$locations = $referenceDataService->getLocations();
$departments = $referenceDataService->getDepartments();
$users = $referenceDataService->getUsers();
$vendors = $referenceDataService->getVendors();

$pageTitle = 'Add Asset';
$pageStylesheet = 'css/create.css';
$pageScript = 'js/create.js';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><div class="text-muted small mb-1">Asset Management</div><h1 class="h3 mb-1">Add Asset</h1><p class="text-muted mb-0">Enter the official asset information. AssetSys will generate its own tracking code.</p></div>
    <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Assets</a>
</div>
<div id="assetFormAlert" class="alert d-none" role="alert"></div>
<div id="assetCreateConfig" data-list-url="<?= e(base_url('/public/assets/')) ?>" hidden></div>
<form id="assetCreateForm" method="post" action="<?= e(base_url('/public/assets/create_process.php')) ?>" novalidate>
<?= Csrf::field() ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0"><i class="fa-solid fa-clipboard-list text-teal me-2"></i>Official Asset Information</h2><p class="text-muted small mb-0 mt-1">Enter the information in the same order as the official inventory record.</p></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="finance_main_location">1. Location (Main)</label><input class="form-control" id="finance_main_location" name="finance_main_location" maxlength="150" placeholder="Enter main location"></div>
        <div class="col-md-6"><label class="form-label" for="finance_owner_department">2. Owner / Department <span class="text-danger">*</span></label><input class="form-control" id="finance_owner_department" name="finance_owner_department" maxlength="150" list="departmentOptions" placeholder="Enter or select owner / department" required><datalist id="departmentOptions"><?php foreach ($departments as $department): ?><option value="<?= e($department['name']) ?>"><?php endforeach; ?></datalist><input type="hidden" id="department_name" name="department_name"></div>
        <div class="col-md-6"><label class="form-label" for="category_id">3. Category <span class="text-danger">*</span></label><select class="form-select" id="category_id" name="category_id" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="finance_item_id">4. Item ID</label><input class="form-control" id="finance_item_id" name="finance_item_id" maxlength="100" placeholder="Enter official Item ID"></div>
        <div class="col-md-6"><label class="form-label" for="asset_name">5. Item Name <span class="text-danger">*</span></label><input class="form-control" id="asset_name" name="asset_name" maxlength="200" required placeholder="Enter item name"><input type="hidden" id="finance_item_name" name="finance_item_name"></div>
        <div class="col-md-6"><label class="form-label" for="finance_ffe_number">6. FFE Number</label><input class="form-control" id="finance_ffe_number" name="finance_ffe_number" maxlength="150" placeholder="Enter FFE number"></div>
        <div class="col-md-6"><label class="form-label" for="finance_code">7. Code</label><input class="form-control" id="finance_code" name="finance_code" maxlength="100" placeholder="Enter official code"></div>
        <div class="col-md-6"><label class="form-label" for="status_id">8. Status <span class="text-danger">*</span></label><select class="form-select" id="status_id" name="status_id" required><option value="">Select status</option><?php foreach ($statuses as $status): ?><option value="<?= (int) $status['id'] ?>"><?= e($status['name']) ?></option><?php endforeach; ?></select><input type="hidden" id="finance_status" name="finance_status"></div>
        <div class="col-md-6"><label class="form-label" for="finance_supplier">9. Supplier</label><input class="form-control" id="finance_supplier" name="finance_supplier" maxlength="150" placeholder="Enter supplier"></div>
        <div class="col-md-6"><label class="form-label" for="finance_po_number">10. PO Number</label><input class="form-control" id="finance_po_number" name="finance_po_number" maxlength="100" placeholder="Enter purchase order number"><input type="hidden" id="purchase_order_number" name="purchase_order_number"></div>
        <div class="col-md-6"><label class="form-label" for="finance_price">11. Price</label><div class="input-group"><span class="input-group-text">PHP</span><input type="number" class="form-control" id="finance_price" name="finance_price" min="0" step="0.01" placeholder="0.00"></div><input type="hidden" id="purchase_cost" name="purchase_cost" value="0.00"><input type="hidden" id="finance_price_text" name="finance_price_text"></div>
        <div class="col-md-6"><label class="form-label" for="purchase_date">12. Date Purchase</label><input type="date" class="form-control" id="purchase_date" name="purchase_date"><input type="hidden" id="finance_date_purchase_text" name="finance_date_purchase_text"></div>
    </div>
    <div class="form-text mt-3">The AssetSys Asset Code is generated automatically after saving and cannot be edited. It is separate from the official Item ID, FFE Number, and Code.</div></div>
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0"><i class="fa-solid fa-sliders text-teal me-2"></i>Operational Details</h2><p class="text-muted small mb-0 mt-1">These fields support AssetSys assignment, location, condition, and lifecycle workflows.</p></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="location_name">Current Physical Location</label><select class="form-select" id="location_name" name="location_name"><option value="">Not yet deployed</option><?php foreach ($locations as $location): ?><option value="<?= e($location['name']) ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="condition_id">Condition</label><select class="form-select" id="condition_id" name="condition_id"><option value="">Select condition</option><?php foreach ($conditions as $condition): ?><option value="<?= (int) $condition['id'] ?>"><?= e($condition['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="assigned_to_name">Assigned Employee</label><input class="form-control" type="text" id="assigned_to_name" name="assigned_to_name" maxlength="150" placeholder="Enter employee name"><div class="form-text">Required only for assets with Assigned status.</div></div>
        <div class="col-md-6"><label class="form-label" for="asset_tag">Asset Tag (optional)</label><input class="form-control" id="asset_tag" name="asset_tag" maxlength="100"></div>
        <div class="col-md-6"><label class="form-label" for="serial_number">Serial Number (optional)</label><input class="form-control" id="serial_number" name="serial_number" maxlength="150"></div>
        <div class="col-md-6"><label class="form-label" for="description">Description (optional)</label><textarea class="form-control" id="description" name="description" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label" for="manufacturer">Manufacturer / Model (optional)</label><div class="input-group"><input class="form-control" id="manufacturer" name="manufacturer" maxlength="150" placeholder="Manufacturer"><input class="form-control" id="model" name="model" maxlength="150" placeholder="Model"></div></div>
    </div></div>
</div>
<details class="card border-0 shadow-sm mb-4">
    <summary class="card-header bg-white py-3 fw-semibold" style="cursor:pointer"><i class="fa-solid fa-gear text-teal me-2"></i>Additional / Depreciation Information <span class="text-muted fw-normal small">(optional)</span></summary>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="model_number">Model Number</label><input class="form-control" id="model_number" name="model_number" maxlength="150"></div>
        <div class="col-md-4"><label class="form-label" for="invoice_number">Invoice Number</label><input class="form-control" id="invoice_number" name="invoice_number" maxlength="100"></div>
        <div class="col-md-4"><label class="form-label" for="supplier_id">Linked Supplier Record</label><select class="form-select" id="supplier_id" name="supplier_id"><option value="">Not linked</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int) $vendor['id'] ?>"><?= e($vendor['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="warranty_start">Warranty Start</label><input type="date" class="form-control" id="warranty_start" name="warranty_start"></div>
        <div class="col-md-4"><label class="form-label" for="warranty_end">Warranty End</label><input type="date" class="form-control" id="warranty_end" name="warranty_end"></div>
        <div class="col-md-4"><label class="form-label" for="useful_life_years">Useful Life (Years) (optional)</label><input type="number" class="form-control" id="useful_life_years" name="useful_life_years" min="0.01" step="0.01"></div>
        <div class="col-md-4"><label class="form-label" for="salvage_value">Salvage Value</label><input type="number" class="form-control" id="salvage_value" name="salvage_value" min="0" step="0.01" value="0"></div>
        <div class="col-12"><p class="form-text mb-0">Useful life is optional. Leave it blank when it is unknown or the asset is not being depreciated. A depreciation schedule requires a valid useful life. Salvage value defaults to zero.</p></div>
        <input type="hidden" name="depreciation_method" value="straight_line">
    </div></div>
</details>
<div class="d-flex justify-content-end gap-2 mb-5"><a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-primary" id="saveAssetButton"><i class="fa-solid fa-floppy-disk me-1"></i> Save Asset</button></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assetCreateForm');
    const owner = document.getElementById('finance_owner_department');
    const department = document.getElementById('department_name');
    const itemName = document.getElementById('asset_name');
    const financeItemName = document.getElementById('finance_item_name');
    const status = document.getElementById('status_id');
    const financeStatus = document.getElementById('finance_status');
    const po = document.getElementById('finance_po_number');
    const legacyPo = document.getElementById('purchase_order_number');
    const price = document.getElementById('finance_price');
    const legacyPrice = document.getElementById('purchase_cost');
    const priceText = document.getElementById('finance_price_text');
    const date = document.getElementById('purchase_date');
    const dateText = document.getElementById('finance_date_purchase_text');
    const location = document.getElementById('location_name');
    const condition = document.getElementById('condition_id');
    const assignee = document.getElementById('assigned_to_name');
    function sync() {
        department.value = owner.value.trim();
        financeItemName.value = itemName.value;
        financeStatus.value = status.selectedIndex > 0 ? status.options[status.selectedIndex].text.trim() : '';
        legacyPo.value = po.value;
        legacyPrice.value = price.value || '0.00';
        priceText.value = price.value;
        dateText.value = date.value;
        const label = status.selectedIndex > 0 ? status.options[status.selectedIndex].text.trim().toLowerCase() : '';
        const needsDeployment = label === 'assigned' || label === 'in use';
        location.required = needsDeployment;
        condition.required = needsDeployment;
        assignee.required = label === 'assigned';
        assignee.disabled = label === 'in use';
    }
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('submit', sync);
    sync();
});
</script>
<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';