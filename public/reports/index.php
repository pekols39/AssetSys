<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Core\Auth;
use App\Services\AssetReportService;
use App\Services\AuthorizationService;
use App\Services\ReferenceDataService;


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
    exit('Access denied. Asset reports are available to Asset Managers only.');
}

$reportService = new AssetReportService();
$referenceDataService = new ReferenceDataService();

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => trim((string) ($_GET['category_id'] ?? '')),
    'status_id' => trim((string) ($_GET['status_id'] ?? '')),
    'condition_id' => trim((string) ($_GET['condition_id'] ?? '')),
    'location_id' => trim((string) ($_GET['location_id'] ?? '')),
];

$categories = $referenceDataService->getAssetCategories();
$statuses = $referenceDataService->getAssetStatuses();
$conditions = $referenceDataService->getAssetConditions();
$locations = $referenceDataService->getLocations();

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
 * Calculate summary totals across ALL matching assets.
 */
$summary = $reportService->getAssetInventorySummary(
    $filters
);

$totalAssets = (int) (
    $summary['total_assets'] ?? 0
);

$totalPurchaseCost = (float) (
    $summary['total_purchase_cost'] ?? 0
);

$totalBookValue = (float) (
    $summary['total_book_value'] ?? 0
);

/*
 * Calculate pagination boundaries.
 */
$totalPages = max(
    1,
    (int) ceil($totalAssets / $perPage)
);

$page = min(
    $requestedPage,
    $totalPages
);

$offset = ($page - 1) * $perPage;

/*
 * Fetch only the assets for the current page.
 */
$assets = $reportService->getAssetInventory(
    $filters,
    $perPage,
    $offset
);

$firstAsset = $totalAssets > 0
    ? $offset + 1
    : 0;

$lastAsset = min(
    $offset + $perPage,
    $totalAssets
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
 * Preserve search and dropdown filters in pagination links.
 */
$pageUrl = static fn(int $targetPage): string =>
    base_url(
        '/public/reports/?'
        . http_build_query(
            array_merge(
                $filters,
                [
                    'per_page' => $perPage,
                    'page' => $targetPage,
                ]
            )
        )
    );

$pageTitle = 'Asset Inventory Report';
$pageStylesheet = 'css/reports.css';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Reports &amp; Analytics</div>

        <h1 class="h3 mb-1">Asset Inventory Report</h1>

        <p class="text-muted mb-0">
            Review company assets, current status, locations, and book values.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2 align-self-center">
        <a
            href="<?= e(base_url('/public/reports/export_excel.php?' . http_build_query($filters))) ?>"
            class="btn btn-outline-success"
        >
            <i class="fa-solid fa-file-excel me-1"></i>
            Export Excel
        </a>

        <a
            href="<?= e(base_url('/public/reports/export_pdf.php?' . http_build_query($filters))) ?>"
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
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Assets in Report</div>
                <div class="h3 mb-0"><?= e(number_format($totalAssets)) ?></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Purchase Cost</div>
                <div class="h3 mb-0">
                    ₱<?= e(number_format($totalPurchaseCost, 2)) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Current Book Value</div>
                <div class="h3 mb-0">
                    ₱<?= e(number_format($totalBookValue, 2)) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-filter me-2 text-teal"></i>
            Report Filters
        </h2>
    </div>

    <div class="card-body">
        <form method="get" action="<?= e(base_url('/public/reports/')) ?>">
            <div class="row g-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="search" class="form-label">Search</label>
                    <input
                        type="search"
                        class="form-control"
                        id="search"
                        name="search"
                        value="<?= e($filters['search']) ?>"
                        placeholder="Asset code, name, serial, supplier..."
                    >
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label for="category_id" class="form-label">Category</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int) $category['id'] ?>"
                                <?= $filters['category_id'] === (string) $category['id'] ? 'selected' : '' ?>
                            >
                                <?= e($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label for="status_id" class="form-label">Status</label>
                    <select class="form-select" id="status_id" name="status_id">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option
                                value="<?= (int) $status['id'] ?>"
                                <?= $filters['status_id'] === (string) $status['id'] ? 'selected' : '' ?>
                            >
                                <?= e($status['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label for="condition_id" class="form-label">Condition</label>
                    <select class="form-select" id="condition_id" name="condition_id">
                        <option value="">All conditions</option>
                        <?php foreach ($conditions as $condition): ?>
                            <option
                                value="<?= (int) $condition['id'] ?>"
                                <?= $filters['condition_id'] === (string) $condition['id'] ? 'selected' : '' ?>
                            >
                                <?= e($condition['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label for="location_id" class="form-label">Location</label>
                    <select class="form-select" id="location_id" name="location_id">
                        <option value="">All locations</option>
                        <?php foreach ($locations as $location): ?>
                            <option
                                value="<?= (int) $location['id'] ?>"
                                <?= $filters['location_id'] === (string) $location['id'] ? 'selected' : '' ?>
                            >
                                <?= e($location['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label for="per_page" class="form-label">Rows Per Page</label>
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

                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>
                        Apply Filters
                    </button>

                    <a
                        href="<?= e(base_url('/public/reports/')) ?>"
                        class="btn btn-outline-secondary"
                    >
                        Clear
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
                <h2 class="h5 mb-1">Inventory Results</h2>
                <div class="text-muted small">
                    <?= e(number_format($totalAssets)) ?> asset(s) matching your filters
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if ($totalAssets === 0): ?>
            <div class="text-center py-5">
                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>

                <h3 class="h5">No matching assets</h3>

                <p class="text-muted mb-0">
                    Try changing your search terms or filters.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Condition</th>
                            <th>Location</th>
                            <th>Assigned To</th>
                            <th class="text-end">Purchase Cost</th>
                            <th class="text-end">Book Value</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($assets as $asset): ?>
                            <?php
                            $assignedName = trim(
                                (string) ($asset['assigned_first_name'] ?? '')
                                . ' '
                                . (string) ($asset['assigned_last_name'] ?? '')
                            );
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e($asset['asset_name'] ?? '') ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= e($asset['asset_code'] ?? '') ?>
                                    </div>
                                    <?php if (!empty($asset['serial_number'])): ?>
                                        <div class="text-muted small">
                                            S/N: <?= e($asset['serial_number']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($asset['category_name'] ?? '—') ?></td>
                                <td><?= e($asset['status_name'] ?? '—') ?></td>
                                <td><?= e($asset['condition_name'] ?? '—') ?></td>
                                <td><?= e($asset['location_name'] ?? '—') ?></td>
                                <td><?= e($assignedName !== '' ? $assignedName : 'Unassigned') ?></td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format((float) ($asset['purchase_cost'] ?? 0), 2)) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    ₱<?= e(number_format((float) ($asset['current_book_value'] ?? 0), 2)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="6" class="text-end">Filtered Totals (all pages)</th>
                            <th class="text-end text-nowrap">
                                ₱<?= e(number_format($totalPurchaseCost, 2)) ?>
                            </th>
                            <th class="text-end text-nowrap">
                                ₱<?= e(number_format($totalBookValue, 2)) ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                <p class="text-muted small mb-0">
                    Showing
                    <?= e(number_format($firstAsset)) ?>–<?= e(number_format($lastAsset)) ?>
                    of <?= e(number_format($totalAssets)) ?> assets
                </p>

                <nav aria-label="Asset inventory pagination">
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

        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>