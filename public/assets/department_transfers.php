<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetDepartmentTransferService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    http_response_code(401);
    exit('Authentication required.');
}


$roleName = (string) ($user['role_name'] ?? '');

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You are not authorized to access this page.');
}

if ((int) ($user['department_id'] ?? 0) <= 0) {
    http_response_code(403);
    exit('Your account is not assigned to a department.');
}

$transferService = new AssetDepartmentTransferService();

$departmentHistory = $transferService->getDepartmentHistory();

$pendingRequests = $roleName === 'Asset Manager'
    ? $transferService->getPendingRequests()
    : [];

$totalTransfers = count($departmentHistory);

$completedCount = count(
    array_filter(
        $departmentHistory,
        static fn(array $request): bool =>
            ($request['request_status'] ?? '') === 'completed'
    )
);

$approvedCount = count(
    array_filter(
        $departmentHistory,
        static fn(array $request): bool =>
            ($request['request_status'] ?? '') === 'approved'
    )
);

$rejectedCount = count(
    array_filter(
        $departmentHistory,
        static fn(array $request): bool =>
            ($request['request_status'] ?? '') === 'rejected'
    )
);


$pageTitle = 'Asset Transfers';
$pageScripts = ['js/department-transfers.js'];

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<?php if ($roleName === 'Asset Manager'): ?>
    <form
        id="departmentTransferDecisionForm"
        action="<?= e(base_url('/public/assets/department_transfer_decision_process.php')) ?>"
        method="post"
        class="d-none"
    >
        <?= \App\Helpers\Csrf::field() ?>
        <input type="hidden" name="request_id" value="">
        <input type="hidden" name="decision" value="">
        <input type="hidden" name="review_notes" value="">
    </form>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Lifecycle</div>
        <h1 class="h3 mb-1">Asset Transfers</h1>
        <p class="text-muted mb-0">
            Submit asset transfer requests and track their approval status.
        </p>
    </div>

    <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-boxes-stacked me-1"></i>
        Assets
    </a>
</div>


<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Transfers</div>
                <div class="fs-3 fw-semibold">
                    <?= e((string) $totalTransfers) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Completed Transfers</div>
                <div class="fs-3 fw-semibold text-success">
                    <?= e((string) $completedCount) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Previously Approved</div>
                <div class="fs-3 fw-semibold">
                    <?= e((string) $approvedCount) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Previously Rejected</div>
                <div class="fs-3 fw-semibold text-danger">
                    <?= e((string) $rejectedCount) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($roleName === 'Asset Manager'): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                <i class="fa-solid fa-clipboard-check me-2 text-teal"></i>
                Pending Asset Transfer Requests
                <span class="badge text-bg-warning ms-2">
                    <?= e((string) count($pendingRequests)) ?>
                </span>
            </h2>
        </div>

        <div class="card-body">
            <?php if ($pendingRequests === []): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa-regular fa-circle-check fs-2 mb-3"></i>
                    <p class="mb-0">
                        There are no pending asset transfer requests.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>From Department</th>
                                <th>To Department</th>
                                <th>Requester</th>
                                <th>Reason</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $request): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <?= e($request['asset_code'] ?? '') ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= e($request['asset_name'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= e($request['source_department_name'] ?? '') ?>
                                    </td>
                                    <td>
                                        <?= e($request['destination_department_name'] ?? '') ?>
                                    </td>
                                    <td>
                                        <?= e(
                                            trim(
                                                (string) ($request['requester_first_name'] ?? '')
                                                . ' '
                                                . (string) ($request['requester_last_name'] ?? '')
                                            ) ?: ($request['requester_username'] ?? 'Unknown user')
                                        ) ?>
                                    </td>
                                    <td class="text-wrap">
                                        <?= e($request['request_reason'] ?? '') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= e($request['requested_at'] ?? '') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-success js-department-transfer-decision"
                                            data-request-id="<?= e((string) ($request['id'] ?? '')) ?>" data-decision="approve">
                                            <i class="fa-solid fa-check me-1"></i>
                                            Approve
                                        </button>

                                        <button type="button" class="btn btn-sm btn-outline-danger js-department-transfer-decision"
                                            data-request-id="<?= e((string) ($request['id'] ?? '')) ?>" data-decision="reject">
                                            <i class="fa-solid fa-xmark me-1"></i>
                                            Reject
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
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-clock-rotate-left me-2 text-teal"></i>
            Asset Transfer History
        </h2>
    </div>

    <div class="card-body">
        <?php if ($departmentHistory === []): ?>
            <div class="text-center text-muted py-4">
                <i class="fa-regular fa-folder-open fs-2 mb-3"></i>
                <p class="mb-0">
                    No asset transfer requests have been recorded for your department.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>From Department</th>
                            <th>To Department</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentHistory as $request): ?>
                            <?php

                            $requestStatus = (string) (
                                $request['request_status'] ?? ''
                            );


                            $statusLabel = match ($requestStatus) {
                                'completed' => 'Completed',
                                'approved' => 'Approved (Legacy)',
                                'rejected' => 'Rejected (Legacy)',
                                'cancelled' => 'Cancelled (Legacy)',
                                'pending' => 'Pending (Legacy)',
                                default => ucfirst($requestStatus !== '' ? $requestStatus : 'Unknown'),
                            };

                            $badgeClass = match ($requestStatus) {
                                'completed' => 'text-bg-success',
                                'approved' => 'text-bg-success',
                                'rejected' => 'text-bg-danger',
                                'cancelled' => 'text-bg-secondary',
                                'pending' => 'text-bg-warning',
                                default => 'text-bg-secondary',
                            };

                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e($request['asset_code'] ?? '') ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= e($request['asset_name'] ?? '') ?>
                                    </div>
                                </td>
                                <td>
                                    <?= e($request['source_department_name'] ?? '') ?>
                                </td>
                                <td>
                                    <?= e($request['destination_department_name'] ?? '') ?>
                                </td>
                                <td class="text-wrap">
                                    <?= e($request['request_reason'] ?? '') ?>
                                </td>

                                <td>
                                    <span class="badge <?= e($badgeClass) ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                </td>

                                <td class="text-nowrap">
                                    <?= e($request['requested_at'] ?? '') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>