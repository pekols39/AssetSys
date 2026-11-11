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

    <div>

        <div class="text-muted small mb-1">
            Asset Management
        </div>

        <h1 class="h3 mb-1">
            Add Asset
        </h1>

        <p class="text-muted mb-0">
            Register a new company asset.
        </p>

    </div>

    <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>
        Back to Assets
    </a>

</div>

<div id="assetFormAlert" class="alert d-none" role="alert"></div>

<div id="assetCreateConfig" data-list-url="<?= e(base_url('/public/assets/')) ?>" hidden></div>

<form id="assetCreateForm" method="post" action="<?= e(base_url('/public/assets/create_process.php')) ?>" novalidate>

    <?= Csrf::field() ?>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h2 class="h5 mb-0">
                <i class="fa-solid fa-box text-teal me-2"></i>
                Asset Information
            </h2>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-12 col-md-6">

                    <label for="asset_code" class="form-label">
                        Asset Code
                    </label>
                    <input type="text" class="form-control" id="asset_code" value="Generated automatically when saved"
                        readonly aria-describedby="asset-code-help">
                    <div class="form-text" id="asset-code-help">
                        The system automatically generates a unique code when the asset is saved.
                    </div>
                </div>

                <div class="col-12 col-md-6">

                    <label for="asset_tag" class="form-label">
                        Asset Tag
                    </label>

                    <input type="text" class="form-control" id="asset_tag" name="asset_tag" maxlength="100">

                </div>

                <div class="col-12 col-md-6">

                    <label for="serial_number" class="form-label">
                        Serial Number
                    </label>

                    <input type="text" class="form-control" id="serial_number" name="serial_number" maxlength="150">

                </div>

                <div class="col-12 col-md-6">

                    <label for="asset_name" class="form-label">
                        Asset Name
                        <span class="text-danger">*</span>
                    </label>

                    <input type="text" class="form-control" id="asset_name" name="asset_name" maxlength="200" required>

                </div>

                <div class="col-12 col-md-6">

                    <label for="category_id" class="form-label">
                        Category
                        <span class="text-danger">*</span>
                    </label>

                    <select class="form-select" id="category_id" name="category_id" required>

                        <option value="">
                            Select category
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option value="<?= e((string) $category['id']) ?>">
                                <?= e($category['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 col-md-6">

                    <label for="status_id" class="form-label">
                        Status
                        <span class="text-danger">*</span>
                    </label>

                    <select class="form-select" id="status_id" name="status_id" required>

                        <option value="">
                            Select status
                        </option>

                        <?php foreach ($statuses as $status): ?>

                            <option value="<?= e((string) $status['id']) ?>">
                                <?= e($status['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12">

                    <label for="description" class="form-label">
                        Description
                    </label>

                    <textarea class="form-control" id="description" name="description" rows="4"></textarea>

                </div>

            </div>

        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h2 class="h5 mb-0">
                <i class="fa-solid fa-industry text-teal me-2"></i>
                Asset Details
            </h2>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-12 col-md-4">

                    <label for="manufacturer" class="form-label">
                        Manufacturer
                    </label>

                    <input type="text" class="form-control" id="manufacturer" name="manufacturer" maxlength="150">

                </div>

                <div class="col-12 col-md-4">

                    <label for="model" class="form-label">
                        Model
                    </label>

                    <input type="text" class="form-control" id="model" name="model" maxlength="150">

                </div>

                <div class="col-12 col-md-4">

                    <label for="model_number" class="form-label">
                        Model Number
                    </label>

                    <input type="text" class="form-control" id="model_number" name="model_number" maxlength="150">

                </div>

            </div>

        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h2 class="h5 mb-0">
                <i class="fa-solid fa-receipt text-teal me-2"></i>
                Purchase Information
            </h2>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-12 col-md-4">

                    <label for="purchase_date" class="form-label">
                        Purchase Date
                    </label>

                    <input type="date" class="form-control" id="purchase_date" name="purchase_date">

                </div>

                <div class="col-12 col-md-4">

                    <label for="purchase_cost" class="form-label">
                        Purchase Cost
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            PHP
                        </span>

                        <input type="number" class="form-control" id="purchase_cost" name="purchase_cost" min="0"
                            step="0.01" value="0.00">

                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <label for="supplier_id" class="form-label">
                        Supplier
                    </label>

                    <select class="form-select" id="supplier_id" name="supplier_id">

                        <option value="">
                            No supplier
                        </option>

                        <?php foreach ($vendors as $vendor): ?>

                            <option value="<?= e((string) $vendor['id']) ?>">
                                <?= e($vendor['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 col-md-6">

                    <label for="invoice_number" class="form-label">
                        Invoice Number
                    </label>

                    <input type="text" class="form-control" id="invoice_number" name="invoice_number" maxlength="100">

                </div>

                <div class="col-12 col-md-6">

                    <label for="purchase_order_number" class="form-label">
                        Purchase Order Number
                    </label>

                    <input type="text" class="form-control" id="purchase_order_number" name="purchase_order_number"
                        maxlength="100">

                </div>

                <div class="col-12 col-md-6">

                    <label for="warranty_start" class="form-label">
                        Warranty Start
                    </label>

                    <input type="date" class="form-control" id="warranty_start" name="warranty_start">

                </div>

                <div class="col-12 col-md-6">

                    <label for="warranty_end" class="form-label">
                        Warranty End
                    </label>

                    <input type="date" class="form-control" id="warranty_end" name="warranty_end">

                </div>

            </div>

        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h2 class="h5 mb-0">
                <i class="fa-solid fa-calculator text-teal me-2"></i>
                Depreciation
            </h2>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-12 col-md-4">

                    <label for="useful_life_years" class="form-label">
                        Useful Life
                    </label>

                    <div class="input-group">

                        <input type="number" class="form-control" id="useful_life_years" name="useful_life_years"
                            min="0.01" step="0.01">

                        <span class="input-group-text">
                            Years
                        </span>

                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <label for="salvage_value" class="form-label">
                        Salvage Value
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            PHP
                        </span>

                        <input type="number" class="form-control" id="salvage_value" name="salvage_value" min="0"
                            step="0.01" value="0.00">

                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <label for="depreciation_method" class="form-label">
                        Depreciation Method
                    </label>

                    <select class="form-select" id="depreciation_method" name="depreciation_method">

                        <option value="straight_line" selected>
                            Straight Line
                        </option>

                    </select>

                </div>

            </div>

        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <h2 class="h5 mb-0">
                <i class="fa-solid fa-location-dot text-teal me-2"></i>
                Assignment & Location
            </h2>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-12 col-md-4">

                    <label for="department_name" class="form-label">
                        Department <span class="text-danger">*</span>
                    </label>

                    <input type="text" class="form-control" id="department_name" name="department_name" maxlength="150"
                        placeholder="Enter department name" required>

                    <div class="form-text">
                        Enter the department responsible for this asset.
                        Existing departments will be reused.
                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <label for="location_name" class="form-label">
                        Location
                        <span class="text-danger d-none" id="locationRequiredMark">*</span>
                    </label>

                    <input type="text" class="form-control" id="location_name" name="location_name" maxlength="150"
                        placeholder="Enter location (optional)">

                </div>

                <div class="col-12 col-md-4">

                    <label for="condition_id" class="form-label">
                        Condition
                        <span class="text-danger d-none" id="conditionRequiredMark">*</span>
                    </label>

                    <select class="form-select" id="condition_id" name="condition_id">

                        <option value="">
                            No condition
                        </option>

                        <?php foreach ($conditions as $condition): ?>

                            <option value="<?= e((string) $condition['id']) ?>">
                                <?= e($condition['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 col-md-4">

                    <label for="assigned_to_name" class="form-label">
                        Assign To
                        <span class="text-danger d-none" id="assignedRequiredMark">*</span>
                    </label>

                    <input type="text" class="form-control" id="assigned_to_name" name="assigned_to_name"
                        maxlength="150" placeholder="Enter employee name">

                    <div class="form-text">
                        Enter the employee's name. A user account is not required.
                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="d-flex flex-wrap justify-content-end gap-2">

        <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">
            Cancel
        </a>

        <button type="submit" class="btn btn-primary" id="saveAssetButton">
            <span class="button-label">
                <i class="fa-solid fa-floppy-disk me-1"></i>
                Save Asset
            </span>

            <span class="button-loading d-none">
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                Saving...
            </span>

        </button>

    </div>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusField = document.getElementById('status_id');
    const locationField = document.getElementById('location_name');
    const conditionField = document.getElementById('condition_id');
    const assignedField = document.getElementById('assigned_to_name');

    const locationMark = document.getElementById('locationRequiredMark');
    const conditionMark = document.getElementById('conditionRequiredMark');
    const assignedMark = document.getElementById('assignedRequiredMark');

    function updateAssignmentRequirements() {
        const selectedStatus = statusField.options[
            statusField.selectedIndex
        ];

        const isAssigned = selectedStatus
            && selectedStatus.text.trim().toLowerCase() === 'assigned';

        locationField.required = isAssigned;
        conditionField.required = isAssigned;
        assignedField.required = isAssigned;

        locationMark.classList.toggle('d-none', !isAssigned);
        conditionMark.classList.toggle('d-none', !isAssigned);
        assignedMark.classList.toggle('d-none', !isAssigned);

        locationField.placeholder = isAssigned
            ? 'Enter location (required)'
            : 'Enter location (optional)';

        assignedField.placeholder = isAssigned
            ? 'Enter employee name (required)'
            : 'Enter employee name (optional)';
    }

    statusField.addEventListener(
        'change',
        updateAssignmentRequirements
    );

    updateAssignmentRequirements();
});
</script>

<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';