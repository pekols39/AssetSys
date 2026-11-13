<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\AssetAssignmentService;
use App\Services\ReferenceDataService;
use App\Services\AssetTransferService;
use App\Services\AssetDepartmentTransferService;
use App\Services\MaintenanceService;
use App\Models\DepreciationSchedule;
use App\Models\DepreciationEntry;
use App\Models\RepairRecord;
use App\Models\AssetPhoto;
use App\Models\AssetDocument;
use App\Models\AssetDisposal;
use App\Services\AuthorizationService;
use App\Services\AuditLogService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to view assets.');
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

$asset = $assetService->findById((int) $id);


if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAsset((int) $asset['id'])) {
    http_response_code(403);
    exit('You do not have permission to view this asset.');
}

/*
 * Record successful access to the asset details page.
 */
(new AuditLogService())->log(
    action: 'view_asset',
    module: 'assets',
    entityType: 'asset',
    entityId: (int) $asset['id'],
    description: 'Asset details viewed.'
);


$canRequestDepartmentTransfer =
    $authorization->isPropertyCustodian()
    && empty($asset['assigned_to_user_id'])
    && !in_array(
        strtolower(trim((string) ($asset['status_name'] ?? ''))),
        [
            'lost',
            'retired',
            'disposed',
            'for disposal',
            'pending disposal',
        ],
        true
    );

$assignmentService = new AssetAssignmentService();
$referenceDataService = new ReferenceDataService();
$transferService = new AssetTransferService();
$departmentTransferService = new AssetDepartmentTransferService();
$maintenanceService = new MaintenanceService();
$depreciationScheduleModel = new DepreciationSchedule();
$depreciationEntryModel = new DepreciationEntry();
$repairModel = new RepairRecord();
$photoModel = new AssetPhoto();
$documentModel = new AssetDocument();
$disposalModel = new AssetDisposal();

$disposalHistory =
    $disposalModel->getByAssetId(
        (int) $asset['id']
    );

$activeAssignment = $assignmentService->findActiveByAssetId(
    (int) $asset['id']
);

$assignmentHistory = $assignmentService->getHistoryByAssetId(
    (int) $asset['id']
);

$users = $referenceDataService->getUsers();

$transferHistory = $transferService->getHistoryByAssetId(
    (int) $asset['id']
);

$departmentTransferHistory =
    $departmentTransferService->getHistoryByAssetId(
        (int) $asset['id']
    );

$maintenanceHistory = $maintenanceService->getByAssetId(
    (int) $asset['id']
);

$repairHistory = $repairModel->getByAssetId(
    (int) $asset['id']
);

$photoHistory =
    $photoModel->getByAssetId((int) $asset['id']);

$documentHistory =
    $documentModel->getByAssetId((int) $asset['id']);

$depreciationSchedule =
    $depreciationScheduleModel->getActiveByAssetId(
        (int) $asset['id']
    );

$depreciationHistory = [];

if ($depreciationSchedule) {

    $depreciationHistory =
        $depreciationEntryModel->getByScheduleId(
            (int) $depreciationSchedule['id']
        );
}

$locations = $referenceDataService->getLocations();

$canAssignAsset =
    empty($asset['assigned_to_user_id'])
    && strtolower(
        (string) ($asset['status_name'] ?? '')
    ) === 'available';

$pageTitle = 'Asset Details';
$pageStylesheet = 'css/view.css';
$pageScript = 'js/assignment.js';

$pageScripts = [
    'js/transfer.js',
    'js/maintenance.js',
    'js/depreciation.js',
    'js/repair.js',
    'js/photo.js',
    'js/document.js',
    'js/disposal.js',
];

require_once dirname(__DIR__) . '/includes/app_header.php';

$statusName = $asset['status_name'] ?? 'Unknown';

$statusClass = match (
strtolower($statusName)
) {
    'available' => 'status-available',
    'assigned' => 'status-assigned',
    'under maintenance' => 'status-maintenance',
    'under repair' => 'status-repair',
    'lost' => 'status-lost',
    'damaged' => 'status-damaged',
    'for disposal' => 'status-disposal',
    'retired' => 'status-retired',
    'disposed' => 'status-disposed',
    default => 'status-default',
};


$assignedTo = trim(
    (string) ($asset['assigned_to_name'] ?? '')
);

if ($assignedTo === '') {
    $assignedTo = trim(
        ($asset['assigned_first_name'] ?? '')
        . ' '
        . ($asset['assigned_last_name'] ?? '')
    );
}

$assignedTo = $assignedTo !== ''
    ? $assignedTo
    : 'Unassigned';


function displayValue(
    mixed $value,
    string $fallback = '—'
): string {
    if (
        $value === null
        || trim((string) $value) === ''
        || strtolower(trim((string) $value)) === 'null'
    ) {
        return $fallback;
    }

    return e((string) $value);
}

function displayDate(
    mixed $value,
    string $fallback = '—'
): string {
    if (
        $value === null
        || $value === ''
    ) {
        return $fallback;
    }

    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return e((string) $value);
    }

    return e(date('F d, Y', $timestamp));
}
?>

<div class="asset-view-page">

    <?= Csrf::field() ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">

        <div>
            <div class="text-muted small mb-1">
                Asset Management
            </div>

            <h1 class="h3 mb-1">
                Asset Details
            </h1>

            <p class="text-muted mb-0">
                View complete information for this company asset.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">

            <a href="<?= e(
                base_url(
                    '/public/assets/qr.php?asset_id='
                    . (int) $asset['id']
                )
            ) ?>" class="btn btn-outline-primary">
                <i class="fa-solid fa-qrcode me-1"></i>
                QR Identity
            </a>

            <a href="<?= e(
                base_url(
                    '/public/assets/edit.php?id='
                    . (int) $asset['id']
                )
            ) ?>" class="btn btn-primary">
                <i class="fa-solid fa-pen-to-square me-1"></i>
                Edit Asset
            </a>

            <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i>
                Back to Assets
            </a>

        </div>

    </div>

    <!-- Asset Header -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body p-4">

            <div class="row align-items-center g-4">

                <div class="col-lg-8">

                    <div class="d-flex align-items-start gap-3">

                        <div class="asset-icon">
                            <i class="fa-solid fa-box"></i>
                        </div>

                        <div>

                            <div class="asset-code-large">
                                <?= e($asset['asset_code']) ?>
                            </div>

                            <h2 class="asset-name mb-2">
                                <?= e($asset['asset_name']) ?>
                            </h2>

                            <?php if (!empty($asset['asset_tag'])): ?>

                                <div class="text-muted small">
                                    Asset Tag:
                                    <strong>
                                        <?= e($asset['asset_tag']) ?>
                                    </strong>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($asset['serial_number'])): ?>

                                <div class="text-muted small">
                                    Serial Number:
                                    <strong>
                                        <?= e($asset['serial_number']) ?>
                                    </strong>
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="col-lg-4 text-lg-end">

                    <span class="asset-status <?= e($statusClass) ?>">
                        <?= e($statusName) ?>
                    </span>

                </div>

            </div>

        </div>

    </div>

    <!-- Overview -->

    <div class="row g-4">

        <!-- Asset Information -->

        <div class="col-lg-6">

            <div class="card shadow-sm border-0 h-100">

                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fa-solid fa-circle-info text-teal me-2"></i>
                        Asset Information
                    </h5>
                </div>

                <div class="card-body">

                    <div class="detail-row">
                        <span class="detail-label">
                            Asset Code
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['asset_code']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Asset Tag
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['asset_tag']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Serial Number
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['serial_number']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Asset Name
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['asset_name']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Category
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['category_name']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Manufacturer
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['manufacturer']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Model
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['model']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Model Number
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['model_number']) ?>
                        </span>
                    </div>

                    <hr class="my-3">
                    <h6 class="text-muted mb-3">Original Asset Workbook Details</h6>

                    <div class="detail-row"><span class="detail-label">Item ID</span><span class="detail-value"><?= displayValue($asset['finance_item_id'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">FFE NUMBER</span><span class="detail-value"><?= displayValue($asset['finance_ffe_number'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">CODE</span><span class="detail-value"><?= displayValue($asset['finance_code'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">Status (Workbook)</span><span class="detail-value"><?= displayValue($asset['finance_status'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">LOCATION (Main)</span><span class="detail-value"><?= displayValue($asset['finance_main_location'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">OWNER / DEPARTMENT</span><span class="detail-value"><?= displayValue($asset['finance_owner_department'] ?? null) ?></span></div>

                </div>

            </div>

        </div>

        <!-- Location & Assignment -->

        <div class="col-lg-6">

            <div class="card shadow-sm border-0 h-100">

                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fa-solid fa-location-dot text-teal me-2"></i>
                        Location & Assignment
                    </h5>
                </div>

                <div class="card-body">

                    <div class="detail-row">
                        <span class="detail-label">
                            Status
                        </span>

                        <span class="detail-value">
                            <span class="asset-status <?= e($statusClass) ?>">
                                <?= e($statusName) ?>
                            </span>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Department
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['finance_owner_department'] ?? null) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Location
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['finance_location'] ?? null) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Assigned To
                        </span>

                        <span class="detail-value">
                            <?php
                            $assignedToDisplay = trim(
                                (string) ($asset['assigned_to_name'] ?? '')
                            );

                            if ($assignedToDisplay === '' && $activeAssignment) {
                                $assignedToDisplay = trim(
                                    (string) ($activeAssignment['assigned_to_name'] ?? '')
                                );

                                if ($assignedToDisplay === '') {
                                    $assignedToDisplay = trim(
                                        ($activeAssignment['first_name'] ?? '')
                                        . ' '
                                        . ($activeAssignment['last_name'] ?? '')
                                    );
                                }
                            }
                            ?>

                            <?= $assignedToDisplay !== ''
                                ? e($assignedToDisplay)
                                : '<span class="text-muted">Unassigned</span>' ?>
                        </span>
                    </div>

                    <?php if ($activeAssignment): ?>

                        <div class="detail-row">
                            <span class="detail-label">
                                Assigned At
                            </span>

                            <span class="detail-value">
                                <?= e(
                                    $activeAssignment['assigned_at']
                                    ?? ''
                                ) ?>
                            </span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">
                                Acknowledgement Reference
                            </span>

                            <span class="detail-value">
                                <?= displayValue(
                                    $activeAssignment[
                                        'acknowledgement_reference'
                                    ] ?? null
                                ) ?>
                            </span>
                        </div>

                        <div class="detail-row">
                            <span class="detail-label">
                                Assignment Remarks
                            </span>

                            <span class="detail-value">
                                <?= displayValue(
                                    $activeAssignment['remarks']
                                    ?? null
                                ) ?>
                            </span>
                        </div>

                        <div class="mt-4">

                            <?= Csrf::field() ?>

                            <button type="button" class="btn btn-outline-danger" id="returnAssetButton"
                                data-assignment-id="<?= (int) $activeAssignment['id'] ?>">
                                <i class="fa-solid fa-rotate-left me-1"></i>
                                Return Asset
                            </button>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <?php if (!empty($assignmentHistory)): ?>

            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-clock-rotate-left text-teal me-2"></i>
                        Assignment History
                    </h2>
                </div>

                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead>
                                <tr>
                                    <th>Assigned To</th>
                                    <th>Assigned At</th>
                                    <th>Assigned By</th>
                                    <th>Status</th>
                                    <th>Returned At</th>
                                    <th>Returned By</th>
                                    <th>Reference</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($assignmentHistory as $history): ?>

                                    <tr>

                                        <td>
                                            <?= e(
                                                trim((string) ($history['assigned_to_name'] ?? ''))
                                                ?: trim(
                                                    ($history['assigned_first_name'] ?? '')
                                                    . ' '
                                                    . ($history['assigned_last_name'] ?? '')
                                                )
                                                ?: '—'
                                            ) ?>

                                            <?php if (!empty($history['assigned_username'])): ?>
                                                <div class="small text-muted">
                                                    <?= e($history['assigned_username']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $history['assigned_at'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                trim(
                                                    ($history['assigned_by_first_name'] ?? '')
                                                    . ' '
                                                    . ($history['assigned_by_last_name'] ?? '')
                                                )
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php
                                            $historyStatus =
                                                strtolower(
                                                    (string) (
                                                        $history['assignment_status']
                                                        ?? ''
                                                    )
                                                );
                                            ?>

                                            <?php if ($historyStatus === 'active'): ?>

                                                <span class="badge text-bg-success">
                                                    Active
                                                </span>

                                            <?php elseif ($historyStatus === 'returned'): ?>

                                                <span class="badge bg-secondary">
                                                    Returned
                                                </span>

                                            <?php elseif ($historyStatus === 'cancelled'): ?>

                                                <span class="badge bg-danger">
                                                    Cancelled
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary">
                                                    <?= e(
                                                        ucfirst(
                                                            $historyStatus
                                                        )
                                                    ) ?>
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= !empty($history['returned_at'])
                                                ? e($history['returned_at'])
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                !empty(
                                                $history['returned_by_first_name']
                                            )
                                                || !empty(
                                                $history['returned_by_last_name']
                                            )
                                            ): ?>

                                                <?= e(
                                                    trim(
                                                        ($history['returned_by_first_name'] ?? '')
                                                        . ' '
                                                        . ($history['returned_by_last_name'] ?? '')
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    —
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= !empty(
                                                $history['acknowledgement_reference']
                                            )
                                                ? e(
                                                    $history[
                                                        'acknowledgement_reference'
                                                    ]
                                                )
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= !empty($history['remarks'])
                                                ? e($history['remarks'])
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <?php if (!empty($transferHistory)): ?>

            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-route text-teal me-2"></i>
                        Transfer History
                    </h2>
                </div>

                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead>
                                <tr>
                                    <th>From Department</th>
                                    <th>To Department</th>
                                    <th>From Location</th>
                                    <th>To Location</th>
                                    <th>From User</th>
                                    <th>To User</th>
                                    <th>Transfer Date</th>
                                    <th>Transferred By</th>
                                    <th>Reason</th>
                                    <th>Reference</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($transferHistory as $history): ?>

                                    <tr>

                                        <td>
                                            <?= !empty($history['from_department_name'])
                                                ? e($history['from_department_name'])
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= !empty($history['to_department_name'])
                                                ? e($history['to_department_name'])
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= !empty(
                                                $history['from_location_name']
                                            )
                                                ? e(
                                                    $history[
                                                        'from_location_name'
                                                    ]
                                                )
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $history['to_location_name']
                                                ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php
                                            $fromUserName = trim(
                                                ($history['from_user_first_name'] ?? '')
                                                . ' '
                                                . ($history['from_user_last_name'] ?? '')
                                            );
                                            ?>

                                            <?= $fromUserName !== ''
                                                ? e($fromUserName)
                                                : '<span class="text-muted">Unassigned</span>' ?>
                                        </td>

                                        <td>
                                            <?php
                                            $toUserName = trim(
                                                ($history['to_user_first_name'] ?? '')
                                                . ' '
                                                . ($history['to_user_last_name'] ?? '')
                                            );
                                            ?>

                                            <?= $toUserName !== ''
                                                ? e($toUserName)
                                                : '<span class="text-muted">Unassigned</span>' ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $history['transfer_date']
                                                ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                trim(
                                                    ($history['transferred_by_first_name'] ?? '')
                                                    . ' '
                                                    . ($history['transferred_by_last_name'] ?? '')
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= !empty(
                                                $history['transfer_reason']
                                            )
                                                ? e(
                                                    $history[
                                                        'transfer_reason'
                                                    ]
                                                )
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= !empty(
                                                $history['transfer_reference']
                                            )
                                                ? e(
                                                    $history[
                                                        'transfer_reference'
                                                    ]
                                                )
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                        <td>
                                            <?= !empty($history['remarks'])
                                                ? e($history['remarks'])
                                                : '<span class="text-muted">—</span>' ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-building-circle-arrow-right text-teal me-2"></i>
                    Asset Transfer History
                </h2>

                <?php if ($canRequestDepartmentTransfer): ?>
                    <a href="<?= e(
                        base_url(
                            '/public/assets/department_transfer_request.php?id='
                            . (int) $asset['id']
                        )
                    ) ?>" class="btn btn-outline-primary">
                        <i class="fa-solid fa-paper-plane me-1"></i>
                        Transfer Department
                    </a>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <?php if (empty($departmentTransferHistory)): ?>
                    <p class="text-muted mb-0">
                        No asset transfers have been recorded for this asset.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>From Department</th>
                                    <th>To Department</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Transferred By</th>
                                    <th>Transfer Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departmentTransferHistory as $history): ?>
                                    <?php
                                    $requesterName = trim(
                                        ($history['requester_first_name'] ?? '')
                                        . ' '
                                        . ($history['requester_last_name'] ?? '')
                                    );

                                    $reviewerName = trim(
                                        ($history['reviewer_first_name'] ?? '')
                                        . ' '
                                        . ($history['reviewer_last_name'] ?? '')
                                    );

                                    $departmentStatus = (string) (
                                        $history['request_status'] ?? ''
                                    );

                                    $departmentBadgeClass = match ($departmentStatus) {
                                        'completed', 'approved' => 'text-bg-success',
                                        'rejected' => 'text-bg-danger',
                                        'cancelled' => 'text-bg-secondary',
                                        default => 'text-bg-warning',
                                    };
                                    ?>
                                    <tr>
                                        <td>
                                            <?= e($history['source_department_name'] ?? '') ?>
                                        </td>
                                        <td>
                                            <?= e($history['destination_department_name'] ?? '') ?>
                                        </td>
                                        <td>
                                            <?= e($history['request_reason'] ?? '') ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= e($departmentBadgeClass) ?>">
                                                <?= e(ucfirst($departmentStatus)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= e($requesterName !== ''
                                                ? $requesterName
                                                : ($history['requester_username'] ?? '')) ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?= e($history['requested_at'] ?? '') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Maintenance History -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">
                            <i class="fa-solid fa-screwdriver-wrench me-2 text-teal"></i>
                            Maintenance History
                        </h5>
                        <small class="text-muted">
                            Maintenance records for this asset
                        </small>
                    </div>

                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#maintenanceModal">
                        <i class="fa-solid fa-plus me-1"></i>
                        Add Maintenance
                    </button>
                </div>
            </div>

            <div class="card-body">
                <?php if (empty($maintenanceHistory)): ?>

                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-screwdriver-wrench fa-2x mb-3"></i>
                        <p class="mb-0">
                            No maintenance records found.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Scheduled</th>
                                    <th>Status</th>
                                    <th>Cost</th>
                                    <th>Next Maintenance</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($maintenanceHistory as $maintenance): ?>

                                    <?php
                                    $maintenanceStatus =
                                        strtolower(
                                            (string) (
                                                $maintenance['maintenance_status']
                                                ?? ''
                                            )
                                        );

                                    $maintenanceJson = json_encode(
                                        $maintenance,
                                        JSON_HEX_TAG
                                        | JSON_HEX_APOS
                                        | JSON_HEX_AMP
                                        | JSON_HEX_QUOT
                                    );
                                    ?>

                                    <tr>
                                        <td>
                                            <?= e(
                                                $maintenance['maintenance_type']
                                                ?? '—'
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= e(
                                                    $maintenance['title']
                                                    ?? '—'
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($maintenance['reference_number'])): ?>
                                                <div class="small text-muted">
                                                    Ref:
                                                    <?= e(
                                                        $maintenance['reference_number']
                                                    ) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $maintenance['scheduled_date']
                                                ?? '—'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="maintenance-status maintenance-status-<?= e(
                                                $maintenanceStatus
                                            ) ?>">
                                                <?= e(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $maintenanceStatus
                                                        )
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            ₱
                                            <?= e(
                                                number_format(
                                                    (float) (
                                                        $maintenance['cost']
                                                        ?? 0
                                                    ),
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $maintenance['next_maintenance_date']
                                                ?? '—'
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm edit-maintenance-button"
                                                data-bs-toggle="modal" data-bs-target="#maintenanceModal" data-maintenance="<?= e(
                                                    $maintenanceJson ?: '{}'
                                                ) ?>">
                                                <i class="fa-solid fa-pen-to-square me-1"></i>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <!-- Add Maintenance Modal -->
        <div class="modal fade" id="maintenanceModal" tabindex="-1" aria-labelledby="maintenanceModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title" id="maintenanceModalLabel">
                            <i class="fa-solid fa-screwdriver-wrench me-2"></i>
                            <span id="maintenanceModalTitle">
                                Add Maintenance
                            </span>
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form id="maintenanceForm" action="<?= e(
                        base_url(
                            '/public/assets/maintenance_create_process.php'
                        )
                    ) ?>" data-update-action="<?= e(
                         base_url(
                             '/public/assets/maintenance_update_process.php'
                         )
                     ) ?>" method="POST">

                        <div class="modal-body">

                            <?= Csrf::field() ?>

                            <input type="hidden" name="asset_id" value="<?= e(
                                (string) $asset['id']
                            ) ?>">

                            <input type="hidden" name="id" id="maintenanceId" value="">

                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label for="maintenanceType" class="form-label">
                                        Maintenance Type
                                        <span class="text-danger">*</span>
                                    </label>

                                    <input type="text" class="form-control" id="maintenanceType" name="maintenance_type"
                                        maxlength="100" required>

                                    <div class="invalid-feedback">
                                        Maintenance type is required.
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="maintenanceTitle" class="form-label">
                                        Title
                                        <span class="text-danger">*</span>
                                    </label>

                                    <input type="text" class="form-control" id="maintenanceTitle" name="title"
                                        maxlength="200" required>

                                    <div class="invalid-feedback">
                                        Maintenance title is required.
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="maintenanceStatus" class="form-label">
                                        Status
                                        <span class="text-danger">*</span>
                                    </label>

                                    <select class="form-select" id="maintenanceStatus" name="maintenance_status"
                                        required>
                                        <option value="scheduled">
                                            Scheduled
                                        </option>

                                        <option value="in_progress">
                                            In Progress
                                        </option>

                                        <option value="completed">
                                            Completed
                                        </option>

                                        <option value="cancelled">
                                            Cancelled
                                        </option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="maintenanceProvider" class="form-label">
                                        Service Provider
                                    </label>

                                    <select class="form-select" id="maintenanceProvider" name="service_provider_id">
                                        <option value="">
                                            No service provider
                                        </option>

                                        <?php foreach ($referenceDataService->getVendors() as $vendor): ?>
                                            <option value="<?= e(
                                                (string) $vendor['id']
                                            ) ?>">
                                                <?= e(
                                                    $vendor['name']
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label for="scheduledDate" class="form-label">
                                        Scheduled Date
                                    </label>

                                    <input type="date" class="form-control" id="scheduledDate" name="scheduled_date">
                                </div>

                                <div class="col-md-4">
                                    <label for="startedAt" class="form-label">
                                        Started At
                                    </label>

                                    <input type="datetime-local" class="form-control" id="startedAt" name="started_at">
                                </div>

                                <div class="col-md-4">
                                    <label for="completedAt" class="form-label">
                                        Completed At
                                    </label>

                                    <input type="datetime-local" class="form-control" id="completedAt"
                                        name="completed_at">
                                </div>

                                <div class="col-md-6">
                                    <label for="maintenanceCost" class="form-label">
                                        Cost
                                    </label>

                                    <input type="number" class="form-control" id="maintenanceCost" name="cost" min="0"
                                        step="0.01" value="0.00">
                                </div>

                                <div class="col-md-6">
                                    <label for="referenceNumber" class="form-label">
                                        Reference Number
                                    </label>

                                    <input type="text" class="form-control" id="referenceNumber" name="reference_number"
                                        maxlength="100">
                                </div>

                                <div class="col-12">
                                    <label for="maintenanceDescription" class="form-label">
                                        Description
                                    </label>

                                    <textarea class="form-control" id="maintenanceDescription" name="description"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="maintenanceFindings" class="form-label">
                                        Findings
                                    </label>

                                    <textarea class="form-control" id="maintenanceFindings" name="findings"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="actionsTaken" class="form-label">
                                        Actions Taken
                                    </label>

                                    <textarea class="form-control" id="actionsTaken" name="actions_taken"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="recommendations" class="form-label">
                                        Recommendations
                                    </label>

                                    <textarea class="form-control" id="recommendations" name="recommendations"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label for="nextMaintenanceDate" class="form-label">
                                        Next Maintenance Date
                                    </label>

                                    <input type="date" class="form-control" id="nextMaintenanceDate"
                                        name="next_maintenance_date">
                                </div>

                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-primary" id="saveMaintenanceButton">
                                <i class="fa-solid fa-floppy-disk me-1"></i>
                                <span id="saveMaintenanceButtonText">
                                    Save Maintenance
                                </span>
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>


        <!-- Repair History -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white border-0 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">
                            <i class="fa-solid fa-screwdriver-wrench me-2 text-teal"></i>
                            Repair History
                        </h5>

                        <small class="text-muted">
                            Repair records for this asset
                        </small>
                    </div>

                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#repairModal">
                        <i class="fa-solid fa-plus me-1"></i>
                        Add Repair
                    </button>
                </div>
            </div>

            <div class="card-body">
                <?php if (empty($repairHistory)): ?>

                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-screwdriver-wrench fa-2x mb-3"></i>

                        <p class="mb-0">
                            No repair records found.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Reported</th>
                                    <th>Status</th>
                                    <th>Cost</th>
                                    <th>Completed</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($repairHistory as $repair): ?>

                                    <?php
                                    $repairStatus = strtolower(
                                        (string) (
                                            $repair['repair_status'] ?? ''
                                        )
                                    );

                                    $repairStatusClass = match ($repairStatus) {
                                        'reported' => 'bg-secondary',
                                        'diagnosed' => 'bg-info',
                                        'in_progress' => 'bg-warning text-dark',
                                        'completed' => 'bg-success',
                                        'cancelled' => 'bg-danger',
                                        default => 'bg-secondary',
                                    };

                                    $repairJson = json_encode(
                                        $repair,
                                        JSON_HEX_TAG
                                        | JSON_HEX_APOS
                                        | JSON_HEX_AMP
                                        | JSON_HEX_QUOT
                                    );
                                    ?>

                                    <tr>
                                        <td>
                                            <?= e(
                                                $repair['repair_type'] ?? '—'
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= e(
                                                    $repair['title'] ?? '—'
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($repair['reference_number'])): ?>
                                                <div class="small text-muted">
                                                    Ref:
                                                    <?= e(
                                                        $repair['reference_number']
                                                    ) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                !empty($repair['reported_at'])
                                                ? date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string) $repair['reported_at']
                                                    )
                                                )
                                                : '—'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge <?= e(
                                                $repairStatusClass
                                            ) ?>">
                                                <?= e(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $repairStatus
                                                        )
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            PHP
                                            <?= number_format(
                                                (float) (
                                                    $repair['cost'] ?? 0
                                                ),
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                !empty($repair['completed_at'])
                                                ? date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string) $repair['completed_at']
                                                    )
                                                )
                                                : '—'
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary edit-repair-button repair-edit-button"
                                                data-bs-toggle="modal" data-bs-target="#repairModal" data-repair="<?= e(
                                                    $repairJson ?: '{}'
                                                ) ?>">
                                                <i class="fa-solid fa-pen-to-square me-1"></i>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">
                        <i class="fa-solid fa-images me-2 asset-section-icon"></i>
                        Asset Photos
                    </h5>
                    <small class="text-muted">
                        Photos associated with this asset
                    </small>
                </div>

                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                    data-bs-target="#photoUploadModal">
                    <i class="fa-solid fa-camera me-1"></i>
                    Upload Photo
                </button>
            </div>

            <div class="card-body">
                <?php if (empty($photoHistory)): ?>

                    <div class="text-center py-4 text-muted">
                        <i class="fa-regular fa-image fa-2x mb-2"></i>

                        <p class="mb-0">
                            No photos have been uploaded for this asset.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="row g-3">
                        <?php foreach ($photoHistory as $photo): ?>

                            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                                <div class="card h-100 border">

                                    <div class="ratio ratio-4x3 bg-light">
                                        <img src="<?= e(base_url('/public/download.php') . '?type=photo&id=' . (int) $photo['id']) ?>"
                                            alt="<?= e($photo['caption'] ?: $photo['original_name']) ?>"
                                            class="card-img-top object-fit-cover">
                                    </div>

                                    <div class="card-body p-2">

                                        <?php if (!empty($photo['caption'])): ?>

                                            <div class="fw-semibold text-truncate">
                                                <?= e($photo['caption']) ?>
                                            </div>

                                        <?php else: ?>

                                            <div class="fw-semibold text-truncate">
                                                <?= e($photo['original_name']) ?>
                                            </div>

                                        <?php endif; ?>

                                        <small class="text-muted d-block">
                                            <?= e($photo['original_name']) ?>
                                        </small>

                                        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">

                                            <?php if ((int) $photo['is_primary'] !== 1): ?>

                                                <button type="button" class="btn btn-sm set-primary-photo-button"
                                                    data-photo-id="<?= (int) $photo['id'] ?>">
                                                    <i class="fa-solid fa-star me-1"></i>
                                                    Set as Primary
                                                </button>

                                            <?php else: ?>

                                                <span class="badge text-bg-success">
                                                    <i class="fa-solid fa-star me-1"></i>
                                                    Primary
                                                </span>

                                            <?php endif; ?>

                                            <button type="button" class="btn btn-sm delete-photo-button"
                                                data-photo-id="<?= (int) $photo['id'] ?>">
                                                <i class="fa-solid fa-trash me-1"></i>
                                                Delete
                                            </button>

                                        </div>

                                    </div>

                                </div>
                            </div>

                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fa-solid fa-file-lines me-2 asset-section-icon"></i>
                    Asset Documents
                </h5>

                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                    data-bs-target="#documentUploadModal">
                    <i class="fa-solid fa-upload me-1"></i>
                    Upload Document
                </button>
            </div>

            <div class="card-body">

                <?php if (empty($documentHistory)): ?>

                    <div class="text-center text-muted py-4">
                        <i class="fa-solid fa-file-circle-xmark fa-2x mb-2"></i>

                        <div>
                            No documents have been uploaded for this asset.
                        </div>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Document Type</th>
                                    <th>Filename</th>
                                    <th>Description</th>
                                    <th>Size</th>
                                    <th>Uploaded</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($documentHistory as $document): ?>

                                    <tr>
                                        <td>
                                            <?= e($document['document_type']) ?>
                                        </td>

                                        <td>
                                            <div class="fw-semibold text-truncate">
                                                <?= e($document['original_name']) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if (!empty($document['description'])): ?>

                                                <?= e($document['description']) ?>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    —
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                ((int) $document['file_size']) / 1024,
                                                1
                                            ) ?>
                                            KB
                                        </td>

                                        <td>
                                            <?= e(
                                                date(
                                                    'M d, Y h:i A',
                                                    strtotime(
                                                        (string) $document['created_at']
                                                    )
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">

                                                <?php if (
                                                    strtolower(
                                                        (string) $document['mime_type']
                                                    ) === 'application/pdf'
                                                ): ?>

                                                    <a href="<?= e(
                                                        base_url('/public/download.php')
                                                        . '?type=document&id='
                                                        . (int) $document['id']
                                                        . '&preview=1'
                                                    ) ?>" class="btn btn-sm preview-document-button" target="_blank"
                                                        rel="noopener">
                                                        <i class="fa-solid fa-eye me-1"></i>
                                                        Preview
                                                    </a>

                                                <?php endif; ?>

                                                <a href="<?= e(
                                                    base_url('/public/download.php')
                                                    . '?type=document&id='
                                                    . (int) $document['id']
                                                ) ?>" class="btn btn-sm download-document-button">
                                                    <i class="fa-solid fa-download me-1"></i>
                                                    Download
                                                </a>

                                                <button type="button" class="btn btn-sm delete-document-button"
                                                    data-document-id="<?= (int) $document['id'] ?>">
                                                    <i class="fa-solid fa-trash me-1"></i>
                                                    Delete
                                                </button>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">

            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fa-solid fa-trash-can me-2 asset-section-icon"></i>
                    Disposal History
                </h5>

                <?php
                $latestDisposal =
                    !empty($disposalHistory)
                    ? $disposalHistory[0]
                    : null;

                $latestDisposalStatus =
                    $latestDisposal
                    ? (string) $latestDisposal['disposal_status']
                    : null;
                ?>

                <?php if (
                    !$latestDisposal
                    || in_array(
                        $latestDisposalStatus,
                        ['cancelled', 'completed'],
                        true
                    )
                ): ?>

                    <?php if (
                        $authorization->isPropertyCustodian()
                        && (int) $asset['status_id'] !== 9
                        && (int) $asset['status_id'] !== 8
                    ): ?>

                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                            data-bs-target="#disposalModal">
                            <i class="fa-solid fa-trash-can me-1"></i>
                            Request Disposal
                        </button>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

            <div class="card-body">

                <?php if (empty($disposalHistory)): ?>

                    <div class="text-center text-muted py-4">

                        <i class="fa-solid fa-box-archive fa-2x mb-2"></i>

                        <div>
                            No disposal records have been created
                            for this asset.
                        </div>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead>

                                <tr>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Reason</th>
                                    <th>Book Value</th>
                                    <th>Proceeds</th>
                                    <th>Status</th>
                                    <th class="text-end">
                                        Action
                                    </th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $disposalHistory
                                    as $disposal
                                ): ?>

                                    <?php
                                    $status =
                                        (string) 
                                        $disposal['disposal_status'];

                                    $statusClass = match (
                                    $status
                                    ) {
                                        'pending' =>
                                            'bg-warning text-dark',

                                        'approved' =>
                                            'bg-info text-dark',

                                        'completed' =>
                                            'bg-success',

                                        'cancelled' =>
                                            'bg-secondary',

                                        default =>
                                            'bg-secondary',
                                    };
                                    ?>

                                    <tr>

                                        <td>
                                            <?= e(
                                                $disposal['disposal_type']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string) 
                                                        $disposal[
                                                            'disposal_date'
                                                        ]
                                                    )
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php
                                            $historyReason = '';

                                            if (
                                                ($disposal['disposal_status'] ?? '') === 'cancelled'
                                                && !empty($disposal['rejection_reason'])
                                            ) {
                                                $historyReason = (string) $disposal['rejection_reason'];
                                            } else {
                                                $historyReason = (string) ($disposal['disposal_reason'] ?? '');
                                            }
                                            ?>

                                            <?php if (trim($historyReason) !== ''): ?>
                                                <?= e($historyReason) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            ₱
                                            <?= number_format(
                                                (float) 
                                                $disposal[
                                                    'book_value_at_disposal'
                                                ],
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            ₱
                                            <?= number_format(
                                                (float) 
                                                $disposal[
                                                    'proceeds'
                                                ],
                                                2
                                            ) ?>
                                        </td>

                                        <td>

                                            <span class="badge <?= e(
                                                $statusClass
                                            ) ?>">
                                                <?= e(
                                                    ucfirst(
                                                        $status
                                                    )
                                                ) ?>
                                            </span>

                                        </td>

                                        <td class="text-end">

                                            <div class="d-flex justify-content-end gap-2">

                                                <?php if (
                                                    $authorization->canAccessAssetMaterialAreas()
                                                    && $status === 'pending'
                                                ): ?>

                                                    <button type="button" class="btn btn-sm disposal-approve-button"
                                                        data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                        <i class="fa-solid fa-check me-1"></i>
                                                        Approve
                                                    </button>

                                                    <button type="button" class="btn btn-sm disposal-reject-button"
                                                        data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                        <i class="fa-solid fa-xmark me-1"></i>
                                                        Reject
                                                    </button>

                                                <?php elseif (
                                                    $authorization->isPropertyCustodian()
                                                    && $status === 'pending'
                                                    && (int) $disposal['disposed_by']
                                                    === (int) ($currentUser['user_id'] ?? 0)
                                                ): ?>

                                                    <button type="button" class="btn btn-sm disposal-cancel-button"
                                                        data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                        <i class="fa-solid fa-ban me-1"></i>
                                                        Withdraw
                                                    </button>

                                                <?php elseif (
                                                    $authorization->isPropertyCustodian()
                                                    && $status === 'approved'
                                                ): ?>

                                                    <button type="button" class="btn btn-sm disposal-complete-button"
                                                        data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                        <i class="fa-solid fa-check-double me-1"></i>
                                                        Complete Disposal
                                                    </button>

                                                <?php else: ?>

                                                    <span class="text-muted">—</span>

                                                <?php endif; ?>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="modal fade" id="disposalModal" tabindex="-1" aria-labelledby="disposalModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title" id="disposalModalLabel">
                            <i class="fa-solid fa-trash-can me-2 asset-section-icon"></i>
                            Request Asset Disposal
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                    </div>

                    <form id="disposalForm" action="<?= e(
                        asset('disposal_create_process.php')
                    ) ?>" method="post">

                        <div class="modal-body">

                            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                            <?= Csrf::field() ?>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label for="disposalType" class="form-label">
                                        Disposal Type
                                        <span class="text-danger">
                                            *
                                        </span>
                                    </label>

                                    <input type="text" class="form-control" id="disposalType" name="disposal_type"
                                        maxlength="100" placeholder="e.g. Sale, Donation, Scrap" required>

                                </div>

                                <div class="col-md-6">

                                    <label for="disposalDate" class="form-label">
                                        Disposal Date
                                        <span class="text-danger">
                                            *
                                        </span>
                                    </label>

                                    <input type="date" class="form-control" id="disposalDate" name="disposal_date"
                                        value="<?= e(
                                            date('Y-m-d')
                                        ) ?>" required>

                                </div>

                                <div class="col-12">

                                    <label for="disposalReason" class="form-label">
                                        Disposal Reason
                                    </label>

                                    <input type="text" class="form-control" id="disposalReason" name="disposal_reason"
                                        maxlength="255" placeholder="Reason for disposal...">

                                </div>

                                <div class="col-md-6">

                                    <label for="disposalValue" class="form-label">
                                        Disposal Value
                                    </label>

                                    <input type="number" class="form-control" id="disposalValue" name="disposal_value"
                                        min="0" step="0.01" value="0.00">

                                </div>

                                <div class="col-md-6">

                                    <label for="disposalProceeds" class="form-label">
                                        Proceeds
                                    </label>

                                    <input type="number" class="form-control" id="disposalProceeds" name="proceeds"
                                        min="0" step="0.01" value="0.00">

                                </div>

                                <div class="col-12">

                                    <label for="disposalReference" class="form-label">
                                        Disposal Reference
                                    </label>

                                    <input type="text" class="form-control" id="disposalReference"
                                        name="disposal_reference" maxlength="100" placeholder="Reference number...">

                                </div>

                                <div class="col-12">

                                    <label for="disposalDescription" class="form-label">
                                        Description
                                    </label>

                                    <textarea class="form-control" id="disposalDescription" name="description" rows="3"
                                        placeholder="Additional disposal details..."></textarea>

                                </div>

                                <div class="col-12">

                                    <label for="disposalRemarks" class="form-label">
                                        Remarks
                                    </label>

                                    <textarea class="form-control" id="disposalRemarks" name="remarks" rows="3"
                                        placeholder="Additional remarks..."></textarea>

                                </div>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-primary" id="saveDisposalButton">
                                <i class="fa-solid fa-trash-can me-1"></i>
                                Submit Disposal Request
                            </button>

                        </div>

                    </form>

                </div>

            </div>
        </div>

        <div class="modal fade" id="documentUploadModal" tabindex="-1" aria-labelledby="documentUploadModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title" id="documentUploadModalLabel">
                            <i class="fa-solid fa-file-arrow-up me-2 asset-section-icon"></i>
                            Upload Document
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form id="documentUploadForm" action="<?= e(asset('document_upload_process.php')) ?>"
                        data-delete-action="<?= e(asset('document_delete_process.php')) ?>" method="post"
                        enctype="multipart/form-data">

                        <div class="modal-body">

                            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="documentType" class="form-label">
                                    Document Type
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="text" class="form-control" id="documentType" name="document_type"
                                    maxlength="100" placeholder="e.g. Invoice, Warranty, Purchase Order" required>
                            </div>

                            <div class="mb-3">
                                <label for="documentDescription" class="form-label">
                                    Description
                                </label>

                                <textarea class="form-control" id="documentDescription" name="description" rows="3"
                                    maxlength="255" placeholder="Enter a short description..."></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="documentFile" class="form-label">
                                    Document
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="file" class="form-control" id="documentFile" name="document"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>

                                <div class="form-text">
                                    Allowed formats: PDF, DOC, DOCX, XLS, XLSX,
                                    JPG, PNG. Maximum size: 10 MB.
                                </div>
                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-primary" id="uploadDocumentButton">
                                <i class="fa-solid fa-upload me-1"></i>
                                Upload Document
                            </button>

                        </div>

                    </form>

                </div>
            </div>
        </div>

        <div class="modal fade" id="photoUploadModal" tabindex="-1" aria-labelledby="photoUploadModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title" id="photoUploadModalLabel">
                            <i class="fa-solid fa-camera me-2"></i>
                            Upload Asset Photo
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form id="photoUploadForm" action="<?= e(asset('photo_upload_process.php')) ?>"
                        data-primary-action="<?= e(asset('photo_primary_process.php')) ?>"
                        data-delete-action="<?= e(asset('photo_delete_process.php')) ?>" method="POST"
                        enctype="multipart/form-data">
                        <div class="modal-body">

                            <input type="hidden" name="asset_id" value="<?= e((string) $asset['id']) ?>">

                            <?= Csrf::field() ?>

                            <div class="mb-3">
                                <label for="photoFile" class="form-label">
                                    Photo <span class="text-danger">*</span>
                                </label>

                                <input type="file" class="form-control" id="photoFile" name="photo"
                                    accept="image/jpeg,image/png,image/webp" required>

                                <div class="form-text">
                                    Accepted formats: JPG, PNG, WEBP.
                                    Maximum size: 10 MB.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="photoCaption" class="form-label">
                                    Caption
                                </label>

                                <input type="text" class="form-control" id="photoCaption" name="caption" maxlength="255"
                                    placeholder="Optional photo caption">
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="photoIsPrimary"
                                    name="is_primary">

                                <label class="form-check-label" for="photoIsPrimary">
                                    Set as primary photo
                                </label>
                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-primary" id="uploadPhotoButton">
                                <i class="fa-solid fa-upload me-1"></i>
                                Upload Photo
                            </button>

                        </div>
                    </form>

                </div>
            </div>
        </div>

        <!-- Add Repair Modal -->
        <div class="modal fade" id="repairModal" tabindex="-1" aria-labelledby="repairModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title" id="repairModalLabel">
                            <i class="fa-solid fa-screwdriver-wrench me-2"></i>

                            <span id="repairModalTitle">
                                Add Repair
                            </span>
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form id="repairForm" action="<?= e(
                        base_url(
                            '/public/assets/repair_create_process.php'
                        )
                    ) ?>" data-update-action="<?= e(
                         base_url(
                             '/public/assets/repair_update_process.php'
                         )
                     ) ?>" method="POST">

                        <div class="modal-body">

                            <?= Csrf::field() ?>

                            <input type="hidden" name="asset_id" value="<?= e(
                                (string) $asset['id']
                            ) ?>">

                            <input type="hidden" name="repair_id" id="repairId" value="">

                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label for="repairType" class="form-label">
                                        Repair Type
                                        <span class="text-danger">*</span>
                                    </label>

                                    <input type="text" class="form-control" id="repairType" name="repair_type"
                                        maxlength="100" required>

                                    <div class="invalid-feedback">
                                        Repair type is required.
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="repairTitle" class="form-label">
                                        Title
                                        <span class="text-danger">*</span>
                                    </label>

                                    <input type="text" class="form-control" id="repairTitle" name="title"
                                        maxlength="200" required>

                                    <div class="invalid-feedback">
                                        Repair title is required.
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="repairStatus" class="form-label">
                                        Status
                                        <span class="text-danger">*</span>
                                    </label>

                                    <select class="form-select" id="repairStatus" name="repair_status" required>
                                        <option value="reported">
                                            Reported
                                        </option>

                                        <option value="diagnosed">
                                            Diagnosed
                                        </option>

                                        <option value="in_progress">
                                            In Progress
                                        </option>

                                        <option value="completed">
                                            Completed
                                        </option>

                                        <option value="cancelled">
                                            Cancelled
                                        </option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="repairProvider" class="form-label">
                                        Service Provider
                                    </label>

                                    <select class="form-select" id="repairProvider" name="service_provider_id">
                                        <option value="">
                                            No service provider
                                        </option>

                                        <?php foreach (
                                            $referenceDataService->getVendors()
                                            as $vendor
                                        ): ?>

                                            <option value="<?= e(
                                                (string) $vendor['id']
                                            ) ?>">
                                                <?= e(
                                                    $vendor['name']
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label for="reportedAt" class="form-label">
                                        Reported At
                                    </label>

                                    <input type="datetime-local" class="form-control" id="reportedAt"
                                        name="reported_at">
                                </div>

                                <div class="col-md-4">
                                    <label for="repairStartedAt" class="form-label">
                                        Started At
                                    </label>

                                    <input type="datetime-local" class="form-control" id="repairStartedAt"
                                        name="started_at">
                                </div>

                                <div class="col-md-4">
                                    <label for="repairCompletedAt" class="form-label">
                                        Completed At
                                    </label>

                                    <input type="datetime-local" class="form-control" id="repairCompletedAt"
                                        name="completed_at">
                                </div>

                                <div class="col-md-6">
                                    <label for="repairCost" class="form-label">
                                        Cost
                                    </label>

                                    <input type="number" class="form-control" id="repairCost" name="cost" min="0"
                                        step="0.01" value="0.00">
                                </div>

                                <div class="col-md-6">
                                    <label for="repairReferenceNumber" class="form-label">
                                        Reference Number
                                    </label>

                                    <input type="text" class="form-control" id="repairReferenceNumber"
                                        name="reference_number" maxlength="100">
                                </div>

                                <div class="col-12">
                                    <label for="problemDescription" class="form-label">
                                        Problem Description
                                        <span class="text-danger">*</span>
                                    </label>

                                    <textarea class="form-control" id="problemDescription" name="problem_description"
                                        rows="3" required></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="repairDiagnosis" class="form-label">
                                        Diagnosis
                                    </label>

                                    <textarea class="form-control" id="repairDiagnosis" name="diagnosis"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="repairActions" class="form-label">
                                        Repair Actions
                                    </label>

                                    <textarea class="form-control" id="repairActions" name="repair_actions"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="partsReplaced" class="form-label">
                                        Parts Replaced
                                    </label>

                                    <textarea class="form-control" id="partsReplaced" name="parts_replaced"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-12">
                                    <label for="repairRecommendations" class="form-label">
                                        Recommendations
                                    </label>

                                    <textarea class="form-control" id="repairRecommendations" name="recommendations"
                                        rows="3"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" value="1" id="warrantyClaim"
                                            name="warranty_claim">

                                        <label class="form-check-label" for="warrantyClaim">
                                            Warranty Claim
                                        </label>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <button type="submit" class="btn btn-primary" id="saveRepairButton">
                                <i class="fa-solid fa-floppy-disk me-1"></i>

                                <span id="saveRepairButtonText">
                                    Save Repair
                                </span>
                            </button>

                        </div>

                    </form>
                </div>
            </div>
        </div>

        <?php if ($canAssignAsset): ?>

            <div class="card shadow-sm mb-4">

                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-user-plus me-2 text-teal"></i>
                        Assign Asset
                    </h2>
                </div>

                <div class="card-body">

                    <form id="assetAssignmentForm" method="post" action="<?= e(
                        base_url(
                            '/public/assets/assign_process.php'
                        )
                    ) ?>" data-view-url="<?= e(
                         base_url(
                             '/public/assets/view.php?id='
                             . (int) $asset['id']
                         )
                     ) ?>">

                        <?= Csrf::field() ?>

                        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="assigned_to_name" class="form-label">
                                    Assign To
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="text" class="form-control" id="assigned_to_name" name="assigned_to_name"
                                    maxlength="150" placeholder="Enter employee name" required />
                            </div>

                            <div class="col-md-6">

                                <label for="acknowledgement_reference" class="form-label">
                                    Acknowledgement Reference
                                </label>

                                <input type="text" class="form-control" id="acknowledgement_reference"
                                    name="acknowledgement_reference" maxlength="100"
                                    placeholder="Optional reference number">

                            </div>

                            <div class="col-12">

                                <label for="assignment_remarks" class="form-label">
                                    Remarks
                                </label>

                                <textarea class="form-control" id="assignment_remarks" name="remarks" rows="3"
                                    placeholder="Optional assignment remarks"></textarea>

                            </div>

                            <div class="col-12">

                                <button type="submit" class="btn btn-primary" id="assignAssetButton">
                                    <i class="fa-solid fa-user-check me-1"></i>
                                    Assign Asset
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

        <?php endif; ?>

        <?php if ($authorization->canAccessAssetMaterialAreas()): ?>

            <div class="card shadow-sm mb-4">

                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">
                        <i class="fa-solid fa-right-left me-2 text-teal"></i>
                        Transfer Asset
                    </h2>
                </div>

                <div class="card-body">

                    <form id="assetTransferForm" method="post" action="<?= e(
                        base_url(
                            '/public/assets/transfer_process.php'
                        )
                    ) ?>" data-view-url="<?= e(
                         base_url(
                             '/public/assets/view.php?id='
                             . (int) $asset['id']
                         )
                     ) ?>">

                        <?= Csrf::field() ?>

                        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="destination_department_name" class="form-label">
                                    Destination Department
                                </label>

                                <input type="text" class="form-control" id="destination_department_name"
                                    name="destination_department_name" maxlength="150"
                                    placeholder="Enter destination department" />

                                <div class="form-text">
                                    Leave blank to keep the current department. Enter a new name to create a department if
                                    it does not already exist.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="destination_location_name" class="form-label">
                                    Destination Location
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="text" class="form-control" id="destination_location_name"
                                    name="destination_location_name" maxlength="150"
                                    placeholder="Enter destination location" required />

                                <div class="form-text">
                                    Enter an existing location or a new location name.
                                </div>
                            </div>


                            <div class="col-md-6">
                                <label class="form-label">
                                    Current Assignee
                                </label>

                                <input type="text" class="form-control" value="<?= e(
                                    trim((string) ($asset['assigned_to_name'] ?? '')) !== ''
                                    ? (string) $asset['assigned_to_name']
                                    : 'Unassigned'
                                ) ?>" readonly />

                                <div class="form-text">
                                    Shows the employee currently assigned to this asset.
                                </div>

                                <div class="alert alert-info mt-2 mb-0" role="note">
                                    <i class="fa-solid fa-circle-info me-1"></i>
                                    <strong>Before transferring:</strong>
                                    If this asset is currently assigned to an employee, return it first
                                    using the <strong>Return Asset</strong> action. Once returned, you
                                    can proceed with the transfer.
                                </div>
                            </div>


                            <div class="col-md-6">
                                <label for="assigned_to_name" class="form-label">
                                    Assign To
                                </label>

                                <input type="text" class="form-control" id="assigned_to_name" name="assigned_to_name"
                                    maxlength="150" placeholder="Enter future employee name" />

                                <div class="form-text">
                                    Optional. Enter the employee who should receive the asset after the transfer.
                                </div>
                            </div>

                            <div class="col-md-6">

                                <label for="transfer_reason" class="form-label">
                                    Transfer Reason
                                </label>

                                <input type="text" class="form-control" id="transfer_reason" name="transfer_reason"
                                    maxlength="255" placeholder="e.g. Asset transfer">

                            </div>

                            <div class="col-md-6">

                                <label for="transfer_reference" class="form-label">
                                    Transfer Reference
                                </label>

                                <input type="text" class="form-control" id="transfer_reference" name="transfer_reference"
                                    maxlength="100" placeholder="Optional reference number">

                            </div>

                            <div class="col-12">

                                <label for="transfer_remarks" class="form-label">
                                    Remarks
                                </label>

                                <textarea class="form-control" id="transfer_remarks" name="remarks" rows="3"
                                    placeholder="Optional transfer remarks"></textarea>

                            </div>

                            <div class="col-12">

                                <button type="submit" class="btn btn-primary" id="transferAssetButton">
                                    <i class="fa-solid fa-right-left me-1"></i>
                                    Transfer Asset
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>
        <?php endif; ?>

        <!-- Purchase Information -->

        <div class="col-lg-6">

            <div class="card shadow-sm border-0 h-100">

                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fa-solid fa-cart-shopping text-teal me-2"></i>
                        Purchase Information
                    </h5>
                </div>

                <div class="card-body">

                    <div class="detail-row">
                        <span class="detail-label">
                            Purchase Date
                        </span>

                        <span class="detail-value">
                            <?= displayDate($asset['purchase_date']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Purchase Cost
                        </span>

                        <span class="detail-value detail-money">
                            PHP
                            <?= number_format(
                                (float) ($asset['purchase_cost'] ?? 0),
                                2
                            ) ?>
                        </span>
                    </div>

                    <div class="detail-row"><span class="detail-label">SUPPLIER (Workbook)</span><span class="detail-value"><?= displayValue($asset['finance_supplier'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">PO NUMBER (Workbook)</span><span class="detail-value"><?= displayValue($asset['finance_po_number'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">Price (Workbook)</span><span class="detail-value"><?= displayValue($asset['finance_price_text'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">DATE PURCHASE (Workbook)</span><span class="detail-value"><?= displayValue($asset['finance_date_purchase_text'] ?? null) ?></span></div>
                    <div class="detail-row"><span class="detail-label">Supplier (AssetSys)</span><span class="detail-value"><?= displayValue($asset['supplier_name']) ?></span></div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Invoice Number
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['invoice_number']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Purchase Order
                        </span>

                        <span class="detail-value">
                            <?= displayValue($asset['purchase_order_number']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Warranty Start
                        </span>

                        <span class="detail-value">
                            <?= displayDate($asset['warranty_start']) ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">
                            Warranty End
                        </span>

                        <span class="detail-value">
                            <?= displayDate($asset['warranty_end']) ?>
                        </span>
                    </div>

                </div>

            </div>

        </div>

        <!-- Depreciation -->

        <div class="col-lg-6">

            <div class="card shadow-sm border-0 h-100">

                <div class="card-header bg-white d-flex justify-content-between align-items-center">

                    <h5 class="mb-0">
                        <i class="fa-solid fa-chart-line text-teal me-2"></i>
                        Depreciation
                    </h5>

                    <div class="d-flex align-items-center gap-2">

                        <?php if ($depreciationSchedule): ?>

                            <?php if (
                                $authorization->canAccessAssetMaterialAreas()
                                && strtolower(
                                    (string) (
                                        $depreciationSchedule['schedule_status']
                                        ?? ''
                                    )
                                ) === 'active'
                            ): ?>

                                <button type="button" class="btn btn-sm btn-primary" id="postDepreciationButton"
                                    data-schedule-id="<?= e(
                                        (string) $depreciationSchedule['id']
                                    ) ?>">
                                    <i class="fa-solid fa-plus me-1"></i>
                                    Post Depreciation
                                </button>

                            <?php endif; ?>

                            <?php
                            $depreciationStatus =
                                strtolower(
                                    (string) (
                                        $depreciationSchedule['schedule_status']
                                        ?? 'active'
                                    )
                                );

                            $depreciationStatusClass = match (
                            $depreciationStatus
                            ) {
                                'active' => 'bg-success',
                                'completed' => 'bg-secondary',
                                'cancelled' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                            ?>

                            <span class="badge <?= e(
                                $depreciationStatusClass
                            ) ?>">
                                <?= e(
                                    ucfirst($depreciationStatus)
                                ) ?>
                            </span>

                        <?php else: ?>

                            <?php if ($authorization->canAccessAssetMaterialAreas()): ?>

                                <button type="button" class="btn btn-sm btn-primary" id="createDepreciationButton"
                                    data-asset-id="<?= e(
                                        (string) $asset['id']
                                    ) ?>" data-start-date="<?= e(
                                         (string) (
                                             $asset['purchase_date'] ?? ''
                                         )
                                     ) ?>">
                                    <i class="fa-solid fa-plus me-1"></i>
                                    Create Schedule
                                </button>

                            <?php endif; ?>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="card-body">

                    <?php if ($depreciationSchedule): ?>

                        <div class="detail-row">

                            <span class="detail-label">
                                Method
                            </span>

                            <span class="detail-value">
                                <?= e(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string) (
                                                $depreciationSchedule[
                                                    'depreciation_method'
                                                ]
                                                ?? '—'
                                            )
                                        )
                                    )
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Start Date
                            </span>

                            <span class="detail-value">
                                <?= displayDate(
                                    $depreciationSchedule['start_date']
                                    ?? null
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Useful Life
                            </span>

                            <span class="detail-value">

                                <?= e(
                                    number_format(
                                        (float) (
                                            $depreciationSchedule[
                                                'useful_life_years'
                                            ]
                                            ?? 0
                                        ),
                                        2
                                    )
                                ) ?>

                                years

                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Original Cost
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'original_cost'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Salvage Value
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'salvage_value'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Depreciable Amount
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'depreciable_amount'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Annual Depreciation
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'annual_depreciation'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Monthly Depreciation
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'monthly_depreciation'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Accumulated Depreciation
                            </span>

                            <span class="detail-value detail-money">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'accumulated_depreciation'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                        <div class="detail-row">

                            <span class="detail-label">
                                Current Book Value
                            </span>

                            <span class="detail-value detail-money fw-semibold">
                                PHP
                                <?= number_format(
                                    (float) (
                                        $depreciationSchedule[
                                            'current_book_value'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="text-center py-4">

                            <div class="text-muted mb-3">
                                <i class="fa-solid fa-chart-line fa-2x"></i>
                            </div>

                            <p class="text-muted mb-0">
                                No active depreciation schedule.
                            </p>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- Depreciation History -->

        <div class="card shadow-sm border-0 mt-4">

            <div class="card-header bg-white">

                <h5 class="mb-0">
                    <i class="fa-solid fa-clock-rotate-left text-teal me-2"></i>
                    Depreciation History
                </h5>

            </div>

            <div class="card-body p-0">

                <?php if (!empty($depreciationHistory)): ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">

                                <tr>
                                    <th>Period</th>
                                    <th>Depreciation</th>
                                    <th>Accumulated</th>
                                    <th>Book Value</th>
                                    <th>Status</th>
                                    <th>Posted By</th>
                                    <th>Posted At</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($depreciationHistory as $entry): ?>

                                    <?php
                                    $entryStatus = strtolower(
                                        (string) (
                                            $entry['entry_status'] ?? 'posted'
                                        )
                                    );

                                    $entryStatusClass = match ($entryStatus) {
                                        'posted' => 'bg-success',
                                        'reversed' => 'bg-danger',
                                        default => 'bg-secondary',
                                    };
                                    ?>

                                    <tr>

                                        <td>
                                            <div>
                                                <?= displayDate(
                                                    $entry['period_start'] ?? null
                                                ) ?>
                                            </div>

                                            <div class="text-muted small">
                                                to
                                                <?= displayDate(
                                                    $entry['period_end'] ?? null
                                                ) ?>
                                            </div>
                                        </td>

                                        <td class="detail-money">
                                            PHP
                                            <?= number_format(
                                                (float) (
                                                    $entry[
                                                        'depreciation_amount'
                                                    ] ?? 0
                                                ),
                                                2
                                            ) ?>
                                        </td>

                                        <td class="detail-money">
                                            PHP
                                            <?= number_format(
                                                (float) (
                                                    $entry[
                                                        'accumulated_depreciation'
                                                    ] ?? 0
                                                ),
                                                2
                                            ) ?>
                                        </td>

                                        <td class="detail-money fw-semibold">
                                            PHP
                                            <?= number_format(
                                                (float) (
                                                    $entry[
                                                        'book_value_after'
                                                    ] ?? 0
                                                ),
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge <?= e(
                                                $entryStatusClass
                                            ) ?>">
                                                <?= e(
                                                    ucfirst($entryStatus)
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php
                                            $postedByName = trim(
                                                (string) (
                                                    ($entry['posted_by_first_name'] ?? '')
                                                    . ' '
                                                    . ($entry['posted_by_last_name'] ?? '')
                                                )
                                            );

                                            $postedByEmployeeNumber = trim(
                                                (string) (
                                                    $entry['posted_by_employee_id']
                                                    ?? ''
                                                )
                                            );
                                            ?>

                                            <?php if ($postedByName !== ''): ?>

                                                <div>
                                                    <?= e($postedByName) ?>
                                                </div>

                                                <?php if ($postedByEmployeeNumber !== ''): ?>

                                                    <div class="text-muted small">
                                                        <?= e($postedByEmployeeNumber) ?>
                                                    </div>

                                                <?php endif; ?>

                                            <?php elseif ($postedByEmployeeNumber !== ''): ?>

                                                <?= e($postedByEmployeeNumber) ?>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                !empty($entry['posted_at'])
                                                ? date(
                                                    'M d, Y h:i A',
                                                    strtotime((string) $entry['posted_at'])
                                                )
                                                : '—'
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="text-center py-5">

                        <div class="text-muted mb-3">
                            <i class="fa-solid fa-clock-rotate-left fa-2x"></i>
                        </div>

                        <p class="text-muted mb-0">
                            No depreciation entries have been posted yet.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- Create Depreciation Schedule Modal -->

        <div class="modal fade" id="createDepreciationModal" tabindex="-1"
            aria-labelledby="createDepreciationModalLabel" aria-hidden="true">
            <div class="modal-dialog">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title" id="createDepreciationModalLabel">
                            <i class="fa-solid fa-chart-line text-teal me-2"></i>
                            Create Depreciation Schedule
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                    </div>

                    <div class="modal-body">

                        <p class="text-muted">
                            Create a straight-line depreciation schedule
                            for this asset.
                        </p>

                        <div class="mb-3">

                            <label for="depreciationStartDate" class="form-label">
                                Start Date
                            </label>

                            <input type="date" class="form-control" id="depreciationStartDate" value="<?= e(
                                (string) (
                                    $asset['purchase_date'] ?? ''
                                )
                            ) ?>">

                            <div class="form-text">
                                The purchase date is used by default.
                            </div>

                        </div>

                        <div class="alert alert-info mb-0">

                            <i class="fa-solid fa-circle-info me-1"></i>

                            The depreciation method, useful life,
                            purchase cost, and salvage value will use
                            the values configured for this asset.

                        </div>

                        <input type="hidden" id="createDepreciationAssetId" value="<?= e(
                            (string) $asset['id']
                        ) ?>">

                        <?= Csrf::field() ?>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="button" class="btn btn-primary" id="saveDepreciationScheduleButton">
                            <i class="fa-solid fa-check me-1"></i>
                            <span id="saveDepreciationScheduleButtonText">
                                Create Schedule
                            </span>
                        </button>

                    </div>

                </div>

            </div>
        </div>

        <!-- Description -->

        <div class="col-12">

            <div class="card shadow-sm border-0">

                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fa-solid fa-align-left text-teal me-2"></i>
                        Description
                    </h5>
                </div>

                <div class="card-body">

                    <?php if (
                        !empty($asset['description'])
                    ): ?>

                        <p class="mb-0 asset-description">
                            <?= nl2br(
                                e($asset['description'])
                            ) ?>
                        </p>

                    <?php else: ?>

                        <p class="text-muted mb-0">
                            No description provided.
                        </p>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
require_once dirname(__DIR__) . '/includes/app_footer.php';
?>