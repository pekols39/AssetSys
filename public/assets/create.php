<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\ReferenceDataService;

AuthMiddleware::handle();

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
                        <span class="text-danger">*</span>
                    </label>

                    <input type="text" class="form-control" id="asset_code" name="asset_code" maxlength="100" required>

                    <div class="form-text">
                        Unique internal asset identifier.
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

                    <label for="department_id" class="form-label">
                        Department
                    </label>

                    <select class="form-select" id="department_id" name="department_id">

                        <option value="">
                            Select department
                        </option>

                        <?php foreach ($departments as $department): ?>

                            <option value="<?= e((string) $department['id']) ?>">
                                <?= e($department['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <div class="form-text">
                        Department responsible for this asset.
                    </div>

                </div>

                <div class="col-12 col-md-4">

                    <label for="location_id" class="form-label">
                        Location
                    </label>

                    <select class="form-select" id="location_id" name="location_id">

                        <option value="">
                            No location
                        </option>

                        <?php foreach ($locations as $location): ?>

                            <option value="<?= e((string) $location['id']) ?>">
                                <?= e($location['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 col-md-4">

                    <label for="condition_id" class="form-label">
                        Condition
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

                    <label for="assigned_to_user_id" class="form-label">
                        Assigned To
                    </label>

                    <select class="form-select" id="assigned_to_user_id" name="assigned_to_user_id">

                        <option value="">
                            Unassigned
                        </option>

                        <?php foreach ($users as $user): ?>

                            <?php
                            $userName = trim(
                                ($user['last_name'] ?? '')
                                . ', '
                                . ($user['first_name'] ?? '')
                            );

                            if (
                                !empty($user['employee_number'])
                            ) {
                                $userName .=
                                    ' ('
                                    . $user['employee_number']
                                    . ')';
                            }
                            ?>

                            <option value="<?= e((string) $user['id']) ?>">
                                <?= e($userName) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

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

<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';