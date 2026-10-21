<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AssetReportService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$user = $authorization->currentUser();

if ($user === null) {
    Auth::logout();

    header('Location: ' . base_url('/public/login.php'));
    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit(
        'Access denied. Depreciation monitoring is available '
        . 'to Asset Managers only.'
    );
}

$departmentId = (int) ($user['department_id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(403);
    exit(
        'Access denied. Your account is not assigned to a department.'
    );
}

$reportService = new AssetReportService($departmentId);

/*
 * Read and validate the requested page.
 */
$requestedPage = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

if (
    $requestedPage === false
    || $requestedPage < 1
) {
    $requestedPage = 1;
}

/*
 * Read and validate schedule filters.
 */
$allowedStatuses = [
    'active',
    'completed',
    'cancelled',
];

$status = trim((string) ($_GET['status'] ?? ''));

if (
    $status !== ''
    && !in_array($status, $allowedStatuses, true)
) {
    $status = '';
}

$allowedPerPage = [10, 25, 50, 100];

$requestedPerPage = filter_var(
    $_GET['per_page'] ?? 10,
    FILTER_VALIDATE_INT
);

$perPage = (
    $requestedPerPage !== false
    && in_array($requestedPerPage, $allowedPerPage, true)
)
    ? $requestedPerPage
    : 10;

/*
 * Obtain totals across all schedules in this department.
 */
$summary = $reportService->getDepreciationScheduleOverviewSummary();

$totalSchedules = (int) (
    $summary['total_schedules'] ?? 0
);

$activeSchedules = (int) (
    $summary['active_schedules'] ?? 0
);

$completedSchedules = (int) (
    $summary['completed_schedules'] ?? 0
);

$totalDepreciableAmount = (float) (
    $summary['total_depreciable_amount'] ?? 0
);

$totalAccumulatedDepreciation = (float) (
    $summary['total_accumulated_depreciation'] ?? 0
);

$totalBookValue = (float) (
    $summary['total_book_value'] ?? 0
);

$totalFuturePostedPeriods = (int) (
    $summary['total_future_posted_periods'] ?? 0
);

/*
 * Calculate pagination using the selected status filter.
 * Summary cards continue using department-wide totals.
 */
$totalFilteredSchedules = $reportService
    ->countDepreciationScheduleOverview(
        $status !== '' ? $status : null
    );

$totalPages = max(
    1,
    (int) ceil($totalFilteredSchedules / $perPage)
);

$page = min(
    $requestedPage,
    $totalPages
);

$offset = ($page - 1) * $perPage;

/*
 * Fetch only the schedules displayed on this page.
 */
$schedules = $reportService->getDepreciationScheduleOverview(
    $perPage,
    $offset,
    $status !== '' ? $status : null
);

$firstSchedule = $totalFilteredSchedules > 0
    ? $offset + 1
    : 0;

$lastSchedule = min(
    $offset + $perPage,
    $totalFilteredSchedules
);

$pageStart = max(
    1,
    min($page - 2, $totalPages - 4)
);

$pageEnd = min(
    $totalPages,
    $pageStart + 4
);

/*
 * Preserve the selected filters when navigating.
 */
$pageUrl = static fn(int $targetPage): string =>
    base_url(
        '/public/reports/depreciation_schedules.php?'
        . http_build_query([
            'page' => $targetPage,
            'per_page' => $perPage,
            'status' => $status,
        ])
    );

$pageTitle = 'Depreciation Monitoring';
$pageStylesheet = 'css/reports.css';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>


<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">
            Reports &amp; Analytics
        </div>

        <h1 class="h3 mb-1">Depreciation Monitoring</h1>

        <p class="text-muted mb-0">
            Monitor depreciation progress, schedule status,
            accumulated depreciation, and current book value.
        </p>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <a
            href="<?= e(base_url(
                '/public/reports/export_depreciation_schedules_excel.php?'
                . http_build_query(['status' => $status])
            )) ?>"
            class="btn btn-outline-success"
        >
            <i class="fa-solid fa-file-excel me-1"></i>
            Export Excel
        </a>

        <a
            href="<?= e(base_url(
                '/public/reports/export_depreciation_schedules_pdf.php?'
                . http_build_query(['status' => $status])
            )) ?>"
            class="btn btn-outline-danger"
        >
            <i class="fa-solid fa-file-pdf me-1"></i>
            Export PDF
        </a>
    </div>
</div>

<?php if ($totalFuturePostedPeriods > 0): ?>
    <div class="alert alert-warning" role="alert">
        <h2 class="h6 mb-1">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            Future-dated depreciation entries detected
        </h2>

        <p class="mb-0">
            There are
            <strong>
                <?= e(number_format($totalFuturePostedPeriods)) ?>
            </strong>
            posted entries whose period-end dates are later than today.
            These records are displayed for review and have not been
            changed by this report.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Total Schedules
                </div>

                <div class="h3 mb-0">
                    <?= e(number_format($totalSchedules)) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Active Schedules
                </div>

                <div class="h3 mb-0">
                    <?= e(number_format($activeSchedules)) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Accumulated Depreciation
                </div>

                <div class="h4 mb-0">
                    ₱<?= e(number_format(
                        $totalAccumulatedDepreciation,
                        2
                    )) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Total Current Book Value
                </div>

                <div class="h4 mb-0">
                    ₱<?= e(number_format($totalBookValue, 2)) ?>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">Filter Depreciation Schedules</h2>
    </div>

    <div class="card-body">
        <form
            method="GET"
            action="<?= e(base_url('/public/reports/depreciation_schedules.php')) ?>"
            class="row g-3 align-items-end"
        >
            <div class="col-12 col-md-5">
                <label for="status" class="form-label">
                    Schedule Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="form-select"
                >
                    <option
                        value=""
                        <?= $status === '' ? 'selected' : '' ?>
                    >
                        All Statuses
                    </option>

                    <option
                        value="active"
                        <?= $status === 'active' ? 'selected' : '' ?>
                    >
                        Active
                    </option>

                    <option
                        value="completed"
                        <?= $status === 'completed' ? 'selected' : '' ?>
                    >
                        Completed
                    </option>

                    <option
                        value="cancelled"
                        <?= $status === 'cancelled' ? 'selected' : '' ?>
                    >
                        Cancelled
                    </option>
                </select>
            </div>

            <div class="col-12 col-md-4">
                <label for="per_page" class="form-label">
                    Rows Per Page
                </label>

                <select
                    id="per_page"
                    name="per_page"
                    class="form-select"
                >
                    <?php foreach ([10, 25, 50, 100] as $option): ?>
                        <option
                            value="<?= $option ?>"
                            <?= $perPage === $option ? 'selected' : '' ?>
                        >
                            <?= $option ?> rows
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-3">
                <div class="d-flex flex-wrap justify-content-start gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-filter me-1"></i>
                        Apply Filters
                    </button>

                    <a
                        href="<?= e(base_url('/public/reports/depreciation_schedules.php')) ?>"
                        class="btn btn-outline-secondary"
                    >
                        <i class="fa-solid fa-rotate-left me-1"></i>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-1">Depreciation Schedules</h2>

                <p class="text-muted small mb-0">
                    <?= e(number_format($totalFilteredSchedules)) ?>
                    schedule(s) matching the current filter.
                    <?= e(number_format($completedSchedules)) ?>
                    completed.
                </p>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if ($totalFilteredSchedules === 0): ?>
            <div class="text-center py-5">
                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-chart-line"></i>
                </div>

                <h3 class="h5">No depreciation schedules found</h3>

                <p class="text-muted mb-0">
                    <?php if ($status !== ''): ?>
                        No depreciation schedules match the selected status.
                    <?php else: ?>
                        There are no depreciation schedules for your department.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th class="text-end">Useful Life</th>
                            <th class="text-end">Posted Periods</th>
                            <th class="text-end">Monthly Depreciation</th>
                            <th class="text-end">Accumulated Depreciation</th>
                            <th class="text-end">Remaining Depreciable Amount</th>
                            <th class="text-end">Current Book Value</th>
                            <th>Progress</th>
                            <th>Future Entries</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <?php
                            $depreciableAmount =
                                (float) ($schedule['depreciable_amount'] ?? 0);

                            $accumulatedDepreciation =
                                (float) ($schedule['accumulated_depreciation'] ?? 0);

                            $remainingAmount = max(
                                0.0,
                                $depreciableAmount - $accumulatedDepreciation
                            );

                            $progress = $depreciableAmount > 0
                                ? min(
                                    100.0,
                                    max(
                                        0.0,
                                        ($accumulatedDepreciation
                                            / $depreciableAmount) * 100
                                    )
                                )
                                : null;

                            $status =
                                (string) ($schedule['schedule_status'] ?? '');

                            $statusClass = match ($status) {
                                'active' => 'text-bg-success',
                                'completed' => 'text-bg-secondary',
                                'cancelled' => 'text-bg-danger',
                                default => 'text-bg-light',
                            };

                            $futureCount =
                                (int) ($schedule['future_posted_periods'] ?? 0);
                            ?>

                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e($schedule['asset_name'] ?? '') ?>
                                    </div>

                                    <div class="text-muted small">
                                        <?= e($schedule['asset_code'] ?? '') ?>

                                        <?php if (!empty($schedule['asset_tag'])): ?>
                                            · <?= e($schedule['asset_tag']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <?= e($schedule['category_name'] ?? '—') ?>
                                </td>

                                <td>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td class="text-end text-nowrap">
                                    <?= e(number_format(
                                        (float) ($schedule['useful_life_years'] ?? 0),
                                        2
                                    )) ?>
                                    years
                                </td>

                                <td class="text-end">
                                    <?= e(number_format(
                                        (int) ($schedule['posted_periods'] ?? 0)
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($schedule['monthly_depreciation'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        $accumulatedDepreciation,
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        $remainingAmount,
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($schedule['current_book_value'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td style="min-width: 150px;">
                                    <?php if ($progress === null): ?>
                                        <span class="text-muted small">
                                            Not applicable
                                        </span>
                                    <?php else: ?>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span>
                                                <?= e(number_format($progress, 1)) ?>%
                                            </span>
                                        </div>

                                        <div class="progress" role="progressbar" aria-label="Depreciation progress"
                                            aria-valuenow="<?= e((string) round($progress, 1)) ?>" aria-valuemin="0"
                                            aria-valuemax="100" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?= e((string) $progress) ?>%;">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($futureCount > 0): ?>
                                        <span class="badge text-bg-warning">
                                            <?= e(number_format($futureCount)) ?>
                                            future
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">
                                            None
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($schedule['last_posted_period_end'])): ?>
                                        <div class="text-muted small mt-1">
                                            Last period:
                                            <?= e($schedule['last_posted_period_end']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                <p class="text-muted small mb-0">
                    Showing
                    <?= e(number_format($firstSchedule)) ?>–<?= e(number_format($lastSchedule)) ?>
                    of <?= e(number_format($totalSchedules)) ?> schedules
                </p>

                <nav aria-label="Depreciation schedule pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <?php if ($page > 1): ?>
                                <a class="page-link" href="<?= e($pageUrl(1)) ?>">First</a>
                            <?php else: ?>
                                <span class="page-link">First</span>
                            <?php endif; ?>
                        </li>

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <?php if ($page > 1): ?>
                                <a class="page-link" href="<?= e($pageUrl($page - 1)) ?>">Previous</a>
                            <?php else: ?>
                                <span class="page-link">Previous</span>
                            <?php endif; ?>
                        </li>

                        <?php for ($number = $pageStart; $number <= $pageEnd; $number++): ?>
                            <li
                                class="page-item <?= $number === $page ? 'active' : '' ?>"
                                <?= $number === $page ? 'aria-current="page"' : '' ?>
                            >
                                <?php if ($number === $page): ?>
                                    <span class="page-link">
                                        <?= e((string) $number) ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        class="page-link"
                                        href="<?= e($pageUrl($number)) ?>"
                                    >
                                        <?= e((string) $number) ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <?php if ($page < $totalPages): ?>
                                <a class="page-link" href="<?= e($pageUrl($page + 1)) ?>">Next</a>
                            <?php else: ?>
                                <span class="page-link">Next</span>
                            <?php endif; ?>
                        </li>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <?php if ($page < $totalPages): ?>
                                <a class="page-link" href="<?= e($pageUrl($totalPages)) ?>">Last</a>
                            <?php else: ?>
                                <span class="page-link">Last</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </nav>
            </div>

            <p class="text-muted small mt-3 mb-0">
                Progress is calculated from accumulated depreciation divided
                by the depreciable amount. Future-dated entries are flagged
                for review; this page does not modify posting history.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>