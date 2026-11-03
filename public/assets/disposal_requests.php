<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\AssetDisposal;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You are not authorized to access disposal history.');
}

$disposalModel = new AssetDisposal();

$canReviewDisposals =
    $authorization->canAccessAssetMaterialAreas();

// Pending approval queue is independent of history filters.
$pendingRequests = $canReviewDisposals
    ? $disposalModel->getPending()
    : [];

// Summary cards always show overall totals.
$disposalSummary = $disposalModel->getSummary();

$totalDisposals = (int) ($disposalSummary['total'] ?? 0);
$completedCount = (int) ($disposalSummary['completed'] ?? 0);
$approvedCount = (int) ($disposalSummary['approved'] ?? 0);
$rejectedCount = (int) ($disposalSummary['rejected'] ?? 0);

// History filters.
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$allowedStatuses = [
    '',
    'pending',
    'approved',
    'completed',
    'cancelled',
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = (int) ($_GET['per_page'] ?? 10);

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}

$historyResult = $disposalModel->getPaginated(
    $search,
    $statusFilter,
    $page,
    $perPage
);

$disposalHistory = $historyResult['data'];
$historyTotal = $historyResult['total'];
$historyPage = $historyResult['page'];
$historyPerPage = $historyResult['per_page'];
$historyTotalPages = $historyResult['total_pages'];

$pageTitle = 'Disposal Requests';
$pageScripts = ['js/disposal.js'];

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<form id="disposalRequestCsrfForm" class="d-none">
    <?= Csrf::field() ?>
</form>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Lifecycle</div>
        <h1 class="h3 mb-1">Disposal</h1>

        <p class="text-muted mb-0">
            <?php if ($canReviewDisposals): ?>
                Review asset disposal requests submitted by Property Custodian
                and approve or reject them.
            <?php else: ?>
                Submit asset disposal requests, track their status,
                and complete approved disposals.
            <?php endif; ?>
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
                <div class="text-muted small mb-2">Total Requests</div>
                <div class="fs-3 fw-semibold">
                    <?= e((string) $totalDisposals) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Completed</div>
                <div class="fs-3 fw-semibold text-success">
                    <?= e((string) $completedCount) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Approved</div>
                <div class="fs-3 fw-semibold">
                    <?= e((string) $approvedCount) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Rejected</div>
                <div class="fs-3 fw-semibold text-danger">
                    <?= e((string) $rejectedCount) ?>
                </div>
            </div>
        </div>
    </div>
</div>


<?php if ($canReviewDisposals): ?>
    <div id="pending-disposal-requests" class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                <i class="fa-solid fa-clipboard-check me-2 text-teal"></i>
                Pending Disposal Requests
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
                        There are no pending disposal requests.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Disposal Type</th>
                                <th>Reason</th>
                                <th>Requested By</th>
                                <th>Disposal Date</th>
                                <th>Requested Value</th>
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
                                        <?= e($request['disposal_type'] ?? '') ?>
                                    </td>
                                    <td class="text-wrap">
                                        <?= e(
                                            $request['disposal_reason']
                                            ?? $request['description']
                                            ?? '—'
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= e(
                                            trim(
                                                (string) ($request['disposed_by_first_name'] ?? '')
                                                . ' '
                                                . (string) ($request['disposed_by_last_name'] ?? '')
                                            ) ?: ($request['disposed_by_employee_id'] ?? 'Unknown user')
                                        ) ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= e($request['disposal_date'] ?? '') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= e(number_format(
                                            (float) ($request['disposal_value'] ?? 0),
                                            2
                                        )) ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="<?= e(base_url(
                                            '/public/assets/view.php?id='
                                            . (int) ($request['asset_id'] ?? 0)
                                        )) ?>" class="btn btn-sm btn-outline-secondary mb-1">
                                            <i class="fa-solid fa-eye me-1"></i>
                                            View Asset
                                        </a>

                                        <button type="button" class="btn btn-sm btn-success disposal-approve-button"
                                            data-disposal-id="<?= (int) $request['id'] ?>">
                                            <i class="fa-solid fa-check me-1"></i>
                                            Approve
                                        </button>

                                        <button type="button" class="btn btn-sm btn-outline-danger disposal-reject-button"
                                            data-disposal-id="<?= (int) $request['id'] ?>">
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

<div class="card border-0 shadow-sm mt-4" id="disposal-history">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-clock-rotate-left me-2 text-teal"></i>
            Disposal History
            <span class="badge text-bg-secondary ms-2">
                <?= e(number_format($historyTotal)) ?>
            </span>
        </h2>
    </div>

    <div class="card-body">
        <form method="GET" action="<?= e(base_url('/public/assets/disposal_requests.php')) ?>#disposal-history"
            class="row g-2 align-items-end mb-4">
            <div class="col-md-4">
                <label for="disposalSearch" class="form-label">
                    Search
                </label>
                <input type="search" class="form-control" id="disposalSearch" name="search" value="<?= e($search) ?>"
                    placeholder="Asset code, name, type, reason...">
            </div>

            <div class="col-md-3">
                <label for="disposalStatus" class="form-label">
                    Status
                </label>
                <select class="form-select" id="disposalStatus" name="status">
                    <option value="">All statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>
                        Pending
                    </option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>
                        Approved
                    </option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>
                        Cancelled
                    </option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="disposalPerPage" class="form-label">
                    Rows
                </label>
                <select class="form-select" id="disposalPerPage" name="per_page">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>>
                            <?= $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fa-solid fa-filter me-1"></i>
                    Filter
                </button>

                <a
                    href="<?= e(base_url('/public/assets/disposal_requests.php#disposal-history')) ?>"
                    class="btn btn-outline-secondary"
                    title="Clear filters"
                    aria-label="Clear filters"
                >
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
        <?php if ($disposalHistory === []): ?>
            <div class="text-center text-muted py-4">
                <i class="fa-regular fa-folder-open fs-2 mb-3"></i>
                <h3 class="h6">
                    <?= $search !== '' || $statusFilter !== ''
                        ? 'No matching disposal records'
                        : 'No disposal history' ?>
                </h3>
                <p class="mb-0">
                    <?= $search !== '' || $statusFilter !== ''
                        ? 'Try changing your search term or status filter.'
                        : 'No disposal history has been recorded.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Disposal Type</th>
                            <th>Disposal Date</th>
                            <th>Reason</th>
                            <th class="text-end">Book Value</th>
                            <th class="text-end">Proceeds</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disposalHistory as $disposal): ?>
                            <?php
                            $status = (string) ($disposal['disposal_status'] ?? '');
                            $historyReason = '';

                            if (
                                $status === 'cancelled'
                                && !empty($disposal['rejection_reason'])
                            ) {
                                $historyReason = (string) $disposal['rejection_reason'];
                            } else {
                                $historyReason = (string) ($disposal['disposal_reason'] ?? '');
                            }

                            $statusClass = match ($status) {
                                'pending' => 'text-bg-warning',
                                'approved' => 'text-bg-info',
                                'completed' => 'text-bg-success',
                                'cancelled' => 'text-bg-secondary',
                                default => 'text-bg-light',
                            };
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e((string) ($disposal['asset_code'] ?? '—')) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= e((string) ($disposal['asset_name'] ?? '—')) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= e((string) ($disposal['disposal_type'] ?? '—')) ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= e((string) ($disposal['disposal_date'] ?? '—')) ?>
                                </td>
                                <td class="text-wrap">
                                    <?= e($historyReason !== '' ? $historyReason : '—') ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    ₱<?= number_format((float) ($disposal['book_value'] ?? 0), 2) ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    ₱<?= number_format((float) ($disposal['proceeds'] ?? 0), 2) ?>
                                </td>
                                <td>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e(ucfirst($status !== '' ? $status : 'unknown')) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        <?php if (
                                            $canReviewDisposals
                                            && $status === 'pending'
                                        ): ?>
                                            <button type="button" class="btn btn-sm btn-success disposal-approve-button"
                                                data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                <i class="fa-solid fa-check me-1"></i>
                                                Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger disposal-reject-button"
                                                data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                <i class="fa-solid fa-xmark me-1"></i>
                                                Reject
                                            </button>
                                        <?php elseif (
                                            $authorization->isPropertyCustodian()
                                            && $status === 'pending'
                                            && (int) ($disposal['disposed_by'] ?? 0)
                                            === (int) ($currentUser['user_id'] ?? 0)
                                        ): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary disposal-cancel-button"
                                                data-disposal-id="<?= (int) $disposal['id'] ?>">
                                                <i class="fa-solid fa-ban me-1"></i>
                                                Withdraw
                                            </button>
                                        <?php elseif (
                                            $authorization->isPropertyCustodian()
                                            && $status === 'approved'
                                        ): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success disposal-complete-button"
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
            <?php
            $historyStart = $historyTotal > 0
                ? (($historyPage - 1) * $historyPerPage) + 1
                : 0;

            $historyEnd = min(
                $historyPage * $historyPerPage,
                $historyTotal
            );

            $historyQuery = [
                'search' => $search,
                'status' => $statusFilter,
                'per_page' => $historyPerPage,
            ];

            $previousUrl = base_url(
                '/public/assets/disposal_requests.php?'
                . http_build_query(
                    array_merge(
                        $historyQuery,
                        ['page' => max(1, $historyPage - 1)]
                    )
                )
                . '#disposal-history'
            );

            $nextUrl = base_url(
                '/public/assets/disposal_requests.php?'
                . http_build_query(
                    array_merge(
                        $historyQuery,
                        ['page' => min($historyTotalPages, $historyPage + 1)]
                    )
                )
                . '#disposal-history'
            );
            ?>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                <small class="text-muted">
                    Showing <?= number_format($historyStart) ?>
                    to <?= number_format($historyEnd) ?>
                    of <?= number_format($historyTotal) ?> records
                </small>

                <nav aria-label="Disposal history pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $historyPage <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($previousUrl) ?>"
                                <?= $historyPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                            >
                                Previous
                            </a>
                        </li>

                        <li class="page-item disabled">
                            <span class="page-link">
                                Page <?= number_format($historyPage) ?>
                                of <?= number_format($historyTotalPages) ?>
                            </span>
                        </li>

                        <li class="page-item <?= $historyPage >= $historyTotalPages ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($nextUrl) ?>"
                                <?= $historyPage >= $historyTotalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                            >
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>