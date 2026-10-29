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

    header(
        'Location: ' . base_url('/public/login.php')
    );

    exit;
}

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);
    exit(
        'Access denied. Depreciation reports are available to Asset Managers only.'
    );
}

$year = filter_var(
    $_GET['year'] ?? date('Y'),
    FILTER_VALIDATE_INT
);

$month = filter_var(
    $_GET['month'] ?? date('n'),
    FILTER_VALIDATE_INT
);

if (
    $year === false
    || $year < 1000
    || $year > 9999
    || $month === false
    || $month < 1
    || $month > 12
) {
    http_response_code(400);
    exit('Invalid depreciation report month or year.');
}

$reportService = new AssetReportService();

/*
 * Determine which page to display.
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
 * Get totals for the entire reporting month.
 */
$summary = $reportService->getDepreciationReportSummary(
    $year,
    $month
);

$totalEntries = (int) (
    $summary['total_entries'] ?? 0
);

$totalDepreciation = (float) (
    $summary['total_depreciation'] ?? 0
);

$totalPages = max(
    1,
    (int) ceil($totalEntries / $perPage)
);

$page = min(
    $requestedPage,
    $totalPages
);

$offset = ($page - 1) * $perPage;

/*
 * Retrieve only the rows for the current page.
 */
$entries = $reportService->getDepreciationReport(
    $year,
    $month,
    $perPage,
    $offset
);

$firstEntry = $totalEntries > 0
    ? $offset + 1
    : 0;

$lastEntry = min(
    $offset + $perPage,
    $totalEntries
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
 * Preserve the selected reporting month and year
 * while navigating between pages.
 */
$pageUrl = static fn(int $targetPage): string =>
    base_url(
        '/public/reports/depreciation.php?'
        . http_build_query([
            'month' => $month,
            'year' => $year,
            'per_page' => $perPage,
            'page' => $targetPage,
        ])
    );

/*
 * Keep the reporting-period heading available to the page.
 */
$periodLabel = (
    new \DateTimeImmutable(
        sprintf('%04d-%02d-01', $year, $month)
    )
)->format('F Y');

$pageTitle = 'Depreciation Report';
$pageStylesheet = 'css/reports.css';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">
            Reports &amp; Analytics
        </div>

        <h1 class="h3 mb-1">Depreciation Report</h1>

        <p class="text-muted mb-0">
            Review posted depreciation entries and book values
            for the selected reporting month.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2 align-self-center">
        <a
            href="<?= e(base_url(
                '/public/reports/export_depreciation_excel.php?'
                . http_build_query([
                    'month' => $month,
                    'year' => $year,
                ])
            )) ?>"
            class="btn btn-outline-success"
        >
            <i class="fa-solid fa-file-excel me-1"></i>
            Export Excel
        </a>

        <a
            href="<?= e(base_url(
                '/public/reports/export_depreciation_pdf.php?'
                . http_build_query([
                    'month' => $month,
                    'year' => $year,
                ])
            )) ?>"
            class="btn btn-outline-danger"
            target="_blank"
            rel="noopener noreferrer"
        >
            <i class="fa-solid fa-file-pdf me-1"></i>
            Export PDF
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Posted Entries
                </div>

                <div class="h3 mb-0">
                    <?= e(number_format($totalEntries)) ?>
                </div>

                <div class="text-muted small mt-2">
                    Periods ending in <?= e($periodLabel) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">
                    Total Depreciation
                </div>

                <div class="h3 mb-0">
                    ₱<?= e(number_format($totalDepreciation, 2)) ?>
                </div>

                <div class="text-muted small mt-2">
                    Posted entries ending in <?= e($periodLabel) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-calendar-days text-teal"></i>
            <div>
                <h2 class="h5 mb-1">Report Filters</h2>
                <p class="text-muted small mb-0">
                    Choose the month and year you want to review.
                </p>
            </div>
        </div>
    </div>

    <div class="card-body p-3 p-lg-4">
        <!-- Reporting period filters -->
        <form method="get" action="<?= e(base_url('/public/reports/depreciation.php')) ?>">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label for="month" class="form-label fw-medium">
                        Reporting Month
                    </label>

                    <select class="form-select" id="month" name="month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>>
                                <?= e(
                                    (new \DateTimeImmutable(
                                        sprintf('2000-%02d-01', $m)
                                    ))->format('F')
                                ) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-12 col-lg-4">
                    <label for="year" class="form-label fw-medium">
                        Reporting Year
                    </label>

                    <input type="number" class="form-control" id="year" name="year" min="1000" max="9999"
                        value="<?= e((string) $year) ?>" required>
                </div>

                <div class="col-12 col-lg-4">
                    <label for="per_page" class="form-label fw-medium">
                        Rows Per Page
                    </label>

                    <select class="form-select" id="per_page" name="per_page">
                        <?php foreach ([10, 25, 50, 100] as $pageSize): ?>
                            <option
                                value="<?= $pageSize ?>"
                                <?= $perPage === $pageSize ? 'selected' : '' ?>
                            >
                                <?= $pageSize ?> rows
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2 justify-content-start">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-filter me-1"></i>
                            Apply Filters
                        </button>

                        <a href="<?= e(base_url('/public/reports/depreciation.php')) ?>"
                            class="btn btn-outline-secondary">
                            <i class="fa-solid fa-rotate-left me-1"></i>
                            Current Month
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-1">Depreciation Entries</h2>

        <div class="text-muted small">
            <?= e($periodLabel) ?>
            · <?= e(number_format($totalEntries)) ?>
            entr<?= $totalEntries === 1 ? 'y' : 'ies' ?>
        </div>
    </div>

    <div class="card-body">
        <?php if ($totalEntries === 0): ?>
            <div class="text-center py-5">
                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-chart-line"></i>
                </div>

                <h3 class="h5">No posted depreciation entries</h3>

                <p class="text-muted mb-0">
                    No posted periods end in <?= e($periodLabel) ?>
                    for your department.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Period Start</th>
                            <th>Period End</th>
                            <th>Asset</th>
                            <th>Category</th>
                            <th class="text-end">Depreciation</th>
                            <th class="text-end">Accumulated</th>
                            <th class="text-end">Opening Book Value</th>
                            <th class="text-end">Closing Book Value</th>
                            <th>Posted By</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td class="text-nowrap">
                                    <?= e($entry['period_start'] ?? '') ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e($entry['period_end'] ?? '') ?>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= e($entry['asset_name'] ?? '') ?>
                                    </div>

                                    <div class="text-muted small">
                                        <?= e($entry['asset_code'] ?? '') ?>
                                    </div>
                                </td>

                                <td>
                                    <?= e($entry['category_name'] ?? '—') ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($entry['depreciation_amount'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($entry['accumulated_depreciation'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($entry['book_value_before'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format(
                                        (float) ($entry['book_value_after'] ?? 0),
                                        2
                                    )) ?>
                                </td>

                                <td>
                                    <?= e(
                                        trim((string) ($entry['posted_by_name'] ?? ''))
                                        ?: 'Unknown'
                                    ) ?>
                                    <div class="text-muted small">
                                        <?= e($entry['posted_at'] ?? '') ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">
                                Total depreciation
                            </th>

                            <th class="text-end text-nowrap">
                                ₱<?= e(number_format($totalDepreciation, 2)) ?>
                            </th>

                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                <p class="text-muted small mb-0">
                    Showing
                    <?= e(number_format($firstEntry)) ?>–<?= e(number_format($lastEntry)) ?>
                    of <?= e(number_format($totalEntries)) ?> entries
                </p>

                <nav aria-label="Depreciation report pagination">
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
                This report includes posted entries whose period-end date
                falls within the selected month. Book values are the values
                recorded when each entry was posted, not today's asset values.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>