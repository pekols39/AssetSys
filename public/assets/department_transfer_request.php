<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    http_response_code(401);
    exit('Authentication required.');
}


if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('Access denied.');
}

$assetId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$assetId || $assetId <= 0) {
    http_response_code(400);
    exit('Invalid asset ID.');
}

$assetService = new AssetService();
$asset = $assetService->findById((int) $assetId);

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

if (!$authorization->canAccessAsset((int) $assetId)) {
    http_response_code(403);
    exit('You do not have permission to request a transfer for this asset.');
}

$sourceDepartmentId = (int) ($asset['department_id'] ?? 0);

if ($sourceDepartmentId <= 0) {
    http_response_code(409);
    exit('The asset must have a current responsible department before it can be transferred.');
}

$sourceDepartmentStmt = \App\Core\Database::connection()->prepare(
    'SELECT name
     FROM departments
     WHERE id = :department_id
     LIMIT 1'
);

$sourceDepartmentStmt->execute([
    'department_id' => $sourceDepartmentId,
]);

$sourceDepartmentName = $sourceDepartmentStmt->fetchColumn();

if ($sourceDepartmentName === false) {
    http_response_code(409);
    exit('The asset’s current department could not be found.');
}

$departmentStmt = \App\Core\Database::connection()->prepare(
    'SELECT id, name
     FROM departments
     WHERE is_active = 1
     ORDER BY name ASC'
);

$departmentStmt->execute();
$departments = $departmentStmt->fetchAll(\PDO::FETCH_ASSOC);

$blockedStatuses = [
    'lost',
    'retired',
    'disposed',
    'for disposal',
    'pending disposal',
];

$statusName = strtolower(
    trim((string) ($asset['status_name'] ?? ''))
);

if (in_array($statusName, $blockedStatuses, true)) {
    http_response_code(409);
    exit('This asset cannot be transferred to another department in its current status.');
}

if (!empty($asset['assigned_to_user_id'])) {
    http_response_code(409);
    exit('Return the asset before requesting a department ownership transfer.');
}

$pageTitle = 'Transfer Department';
$pageScripts = ['js/department-transfers.js'];

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Lifecycle</div>
        <h1 class="h3 mb-1">Transfer Department</h1>
        <p class="text-muted mb-0">
            Submit a department-transfer request for Asset Manager approval.
        </p>
    </div>

    <a href="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>
        Back to Asset
    </a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-box me-2 text-teal"></i>
            Asset Information
        </h2>
    </div>

    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="text-muted small">Asset Code</div>
                <div class="fw-semibold">
                    <?= e($asset['asset_code'] ?? '') ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="text-muted small">Asset Name</div>
                <div class="fw-semibold">
                    <?= e($asset['asset_name'] ?? '') ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="text-muted small">Current Department</div>
                <div class="fw-semibold">
                    <?= e((string) $sourceDepartmentName) ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="text-muted small">Current Location</div>
                <div class="fw-semibold">
                    <?= e($asset['location_name'] ?? 'Not set') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-building-circle-arrow-right me-2 text-teal"></i>
            Transfer Details
        </h2>
    </div>

    <div class="card-body">

        <form id="departmentTransferRequestForm" method="post"
            action="<?= e(base_url('/public/assets/department_transfer_create_process.php')) ?>"
            data-success-url="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>">
            <?= \App\Helpers\Csrf::field() ?>

            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

            <div class="mb-3">
                <label for="destination_department_name" class="form-label">
                    Destination Department
                    <span class="text-danger">*</span>
                </label>

                <input type="text" id="destination_department_name" name="destination_department_name"
                    class="form-control" maxlength="150" required autocomplete="organization"
                    placeholder="Enter the receiving department">

                <div class="form-text">
                    Enter the department responsible for this asset.
                    The destination does not need an AssetSys user account.
                </div>
            </div>

            <div class="mb-4">
                <label for="request_reason" class="form-label">
                    Reason for Transfer
                    <span class="text-danger">*</span>
                </label>

                <textarea id="request_reason" name="request_reason" class="form-control" rows="4" maxlength="500"
                    required placeholder="Explain why this asset needs to move to another department."></textarea>

                <div class="form-text">
                    Maximum 500 characters.
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info me-2"></i>
                Your request will remain pending until the Asset Manager reviews it.
                The asset's department will not change unless the request is approved.
                Its current location and employee assignment will remain unchanged.
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2">
                <a href="<?= e(base_url('/public/assets/view.php?id=' . (int) $asset['id'])) ?>"
                    class="btn btn-outline-secondary">
                    Cancel
                </a>

                <button type="submit" class="btn btn-primary" id="submitDepartmentTransferButton">
                    <i class="fa-solid fa-paper-plane me-1"></i>
                    Submit Transfer Request
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>