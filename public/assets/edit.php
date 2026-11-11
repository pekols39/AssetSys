<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\ReferenceDataService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetOperations()) {
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
?>

<div class="asset-edit-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <div class="text-muted small mb-1">
                Asset / Edit
            </div>

            <h1 class="h3 mb-1">
                Edit Asset
            </h1>

            <p class="text-muted mb-0">
                <?= e($asset['asset_code'] ?? '') ?>
                —
                <?= e($asset['asset_name'] ?? '') ?>
            </p>
        </div>

        <a href="<?= e(
            base_url(
                '/public/assets/view.php?id='
                . (int) $asset['id']
            )
        ) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>
            Back to Asset
        </a>
    </div>


    <form id="assetEditForm" method="post" action="<?= e(
        base_url('/public/assets/edit_process.php')
    ) ?>" data-view-url="<?= e(
         base_url(
             '/public/assets/view.php?id='
             . (int) $asset['id']
         )
     ) ?>" novalidate>

        <?= \App\Helpers\Csrf::field() ?>

        <input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-white">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-box-open me-2 text-teal"></i>
                    Asset Information
                </h2>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label for="asset_code" class="form-label">
                            Asset Code
                        </label>

                        <input type="text" class="form-control" id="asset_code"
                            value="<?= e($asset['asset_code'] ?? '') ?>" readonly aria-describedby="asset-code-help">

                        <div class="form-text" id="asset-code-help">
                            Asset codes cannot be changed after creation.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label for="asset_tag" class="form-label">
                            Asset Tag
                        </label>

                        <input type="text" class="form-control" id="asset_tag" name="asset_tag" maxlength="100" value="<?= e(
                            $asset['asset_tag'] ?? ''
                        ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="serial_number" class="form-label">
                            Serial Number
                        </label>

                        <input type="text" class="form-control" id="serial_number" name="serial_number" maxlength="150"
                            value="<?= e(
                                $asset['serial_number'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-md-8">
                        <label for="asset_name" class="form-label">
                            Asset Name
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text" class="form-control" id="asset_name" name="asset_name" maxlength="200" value="<?= e(
                            $asset['asset_name'] ?? ''
                        ) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label for="category_id" class="form-label">
                            Category
                            <span class="text-danger">*</span>
                        </label>

                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">
                                Select category
                            </option>

                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (int) $asset['category_id'] === (int) $category['id']
                                       ? 'selected'
                                       : '' ?>>
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="status_id" class="form-label">
                            Status
                            <span class="text-danger">*</span>
                        </label>

                        <select class="form-select" id="status_id" name="status_id" required>
                            <option value="">
                                Select status
                            </option>

                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= (int) $status['id'] ?>" <?= (int) $asset['status_id'] === (int) $status['id']
                                       ? 'selected'
                                       : '' ?>>
                                    <?= e($status['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$isCurrentlyAssigned): ?>
                            <div class="form-text">
                                To assign this asset, use the Assign Asset workflow
                                from the asset details page.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label for="condition_id" class="form-label">
                            Condition
                        </label>

                        <select class="form-select" id="condition_id" name="condition_id">
                            <option value="">
                                Select condition
                            </option>

                            <?php foreach ($conditions as $condition): ?>
                                <option value="<?= (int) $condition['id'] ?>" <?= (int) ($asset['condition_id'] ?? 0) === (int) $condition['id']
                                       ? 'selected'
                                       : '' ?>>
                                    <?= e($condition['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="manufacturer" class="form-label">
                            Manufacturer
                        </label>

                        <input type="text" class="form-control" id="manufacturer" name="manufacturer" maxlength="150"
                            value="<?= e(
                                $asset['manufacturer'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="model" class="form-label">
                            Model
                        </label>

                        <input type="text" class="form-control" id="model" name="model" maxlength="150" value="<?= e(
                            $asset['model'] ?? ''
                        ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="model_number" class="form-label">
                            Model Number
                        </label>

                        <input type="text" class="form-control" id="model_number" name="model_number" maxlength="150"
                            value="<?= e(
                                $asset['model_number'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label">
                            Description
                        </label>

                        <textarea class="form-control" id="description" name="description"
                            rows="4"><?= e($asset['description'] ?? '') ?></textarea>
                    </div>

                </div>

            </div>
        </div>

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-white">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-money-bill-wave me-2 text-teal"></i>
                    Purchase Information
                </h2>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label for="purchase_date" class="form-label">
                            Purchase Date
                        </label>

                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" value="<?= e(
                            $asset['purchase_date'] ?? ''
                        ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="purchase_cost" class="form-label">
                            Purchase Cost
                        </label>

                        <input type="number" class="form-control" id="purchase_cost" name="purchase_cost" min="0"
                            step="0.01" value="<?= e(
                                $asset['purchase_cost'] ?? '0.00'
                            ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="supplier_id" class="form-label">
                            Supplier
                        </label>

                        <select class="form-select" id="supplier_id" name="supplier_id">
                            <option value="">
                                Select supplier
                            </option>

                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?= (int) $vendor['id'] ?>" <?= (int) ($asset['supplier_id'] ?? 0) === (int) $vendor['id']
                                       ? 'selected'
                                       : '' ?>>
                                    <?= e($vendor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="invoice_number" class="form-label">
                            Invoice Number
                        </label>

                        <input type="text" class="form-control" id="invoice_number" name="invoice_number"
                            maxlength="100" value="<?= e(
                                $asset['invoice_number'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-md-6">
                        <label for="purchase_order_number" class="form-label">
                            Purchase Order Number
                        </label>

                        <input type="text" class="form-control" id="purchase_order_number" name="purchase_order_number"
                            maxlength="100" value="<?= e(
                                $asset['purchase_order_number'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-md-6">
                        <label for="warranty_start" class="form-label">
                            Warranty Start
                        </label>

                        <input type="date" class="form-control" id="warranty_start" name="warranty_start" value="<?= e(
                            $asset['warranty_start'] ?? ''
                        ) ?>">
                    </div>

                    <div class="col-md-6">
                        <label for="warranty_end" class="form-label">
                            Warranty End
                        </label>

                        <input type="date" class="form-control" id="warranty_end" name="warranty_end" value="<?= e(
                            $asset['warranty_end'] ?? ''
                        ) ?>">
                    </div>

                </div>

            </div>
        </div>

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-white">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-calculator me-2 text-teal"></i>
                    Depreciation
                </h2>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label for="useful_life_years" class="form-label">
                            Useful Life (Years)
                        </label>

                        <input type="number" class="form-control" id="useful_life_years" name="useful_life_years"
                            min="0.01" step="0.01" value="<?= e(
                                $asset['useful_life_years'] ?? ''
                            ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="salvage_value" class="form-label">
                            Salvage Value
                        </label>

                        <input type="number" class="form-control" id="salvage_value" name="salvage_value" min="0"
                            step="0.01" value="<?= e(
                                $asset['salvage_value'] ?? '0.00'
                            ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="depreciation_method" class="form-label">
                            Depreciation Method
                        </label>

                        <select class="form-select" id="depreciation_method" name="depreciation_method">
                            <option value="straight_line" <?= ($asset['depreciation_method'] ?? 'straight_line') === 'straight_line'
                                ? 'selected'
                                : '' ?>>
                                Straight Line
                            </option>
                        </select>
                    </div>

                </div>

            </div>
        </div>

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-white">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-location-dot me-2 text-teal"></i>
                    Location & Assignment
                </h2>
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <div class="form-label">
                            Current Location
                        </div>

                        <p class="form-control-plaintext mb-0">
                            <?= e(
                                $asset['location_name'] ?? 'Not set'
                            ) ?>
                        </p>
                    </div>

                    <div class="col-md-6">
                        <div class="form-label">
                            Assigned To
                        </div>


                        <?php
                        $assignedName = trim(
                            (string) ($asset['assigned_to_name'] ?? '')
                        );

                        if ($assignedName === '') {
                            $assignedName = trim(
                                (string) ($asset['assigned_first_name'] ?? '')
                                . ' '
                                . (string) ($asset['assigned_last_name'] ?? '')
                            );
                        }
                        ?>

                        <p class="form-control-plaintext mb-0">
                            <?= $assignedName !== ''
                                ? e($assignedName)
                                : 'Unassigned' ?>
                        </p>

                    </div>

                </div>

                <p class="form-text mt-3 mb-0">
                    Use the assignment, return, or transfer actions
                    on the Asset Details page to change these values.
                    Those workflows preserve movement history.
                </p>
            </div>

        </div>

        <div class="d-flex justify-content-end gap-2 mb-5">

            <a href="<?= e(
                base_url(
                    '/public/assets/view.php?id='
                    . (int) $asset['id']
                )
            ) ?>" class="btn btn-outline-secondary">
                Cancel
            </a>

            <button type="submit" class="btn btn-primary" id="saveAssetButton">
                <i class="fa-solid fa-floppy-disk me-1"></i>
                Save Changes
            </button>

        </div>

    </form>

</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>