<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\StockService;
use App\Services\MaterialReplenishmentService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to access this area.');
}

$stockService = new StockService();
$replenishmentService = new MaterialReplenishmentService();

$replenishmentFilters = [
    'search' => trim((string) ($_GET['replenishment_search'] ?? '')),
    'date_from' => trim((string) ($_GET['replenishment_date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['replenishment_date_to'] ?? '')),
];

$replenishmentPageNumber = max(
    1,
    (int) ($_GET['replenishment_page'] ?? 1)
);

$replenishmentPerPage = (int) (
    $_GET['replenishment_per_page'] ?? 10
);

if (!in_array($replenishmentPerPage, [10, 25, 50, 100], true)) {
    $replenishmentPerPage = 10;
}

$replenishmentMaterials =
    $replenishmentService->getMaterialOptions();

$replenishmentPage = $replenishmentService->getHistory(
    $replenishmentFilters,
    $replenishmentPageNumber,
    $replenishmentPerPage
);

$replenishmentHistory = $replenishmentPage['items'];
$replenishmentTotal = $replenishmentPage['total'];
$replenishmentPageNumber = $replenishmentPage['page'];
$replenishmentTotalPages = $replenishmentPage['total_pages'];

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => max(0, (int) ($_GET['category_id'] ?? 0)),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'sort' => (string) ($_GET['sort'] ?? 'material_name'),
    'direction' => strtolower((string) ($_GET['direction'] ?? 'asc')),
];

if (!in_array($filters['status'], ['all', 'reorder', 'low', 'in_stock'], true)) {
    $filters['status'] = 'all';
}

if (!in_array($filters['sort'], [
    'material_name',
    'material_code',
    'quantity',
    'minimum_stock_level',
    'reorder_level',
], true)) {
    $filters['sort'] = 'material_name';
}

if (!in_array($filters['direction'], ['asc', 'desc'], true)) {
    $filters['direction'] = 'asc';
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$allowedPageSizes = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 10);

if (!in_array($perPage, $allowedPageSizes, true)) {
    $perPage = 10;
}

$stockPage = $stockService->getDepartmentStockPage(
    $filters,
    $page,
    $perPage
);

$stocks = $stockPage['items'];
$totalStockCount = $stockPage['total'];
$page = $stockPage['page'];
$totalPages = $stockPage['total_pages'];

$lowStockItems = $stockService->getLowStock();
$lowStockCount = count($lowStockItems);
$trackedStockCount = $totalStockCount;

$pageTitle = 'Department Material Stock';

require_once dirname(__DIR__) . '/includes/app_header.php';

$baseStockUrl = base_url('/public/materials/stock.php');

$queryParams = [
    'search' => $filters['search'],
    'category_id' => $filters['category_id'],
    'status' => $filters['status'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
    'per_page' => $perPage,
];

$paginationUrl = static function (int $targetPage) use (
    $baseStockUrl,
    $queryParams
): string {
    return $baseStockUrl . '?' . http_build_query(
        array_merge($queryParams, ['page' => $targetPage])
    );
};

?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Materials &amp; Stock</div>

        <h1 class="h3 mb-1">Department Material Stock</h1>

        <p class="text-muted mb-0">
            Monitor available materials and identify items that need replenishment.
        </p>
    </div>

    <div>
        <a
            href="<?= e(base_url('/public/materials/index.php')) ?>"
            class="btn btn-outline-secondary"
        >
            <i class="fa-solid fa-boxes-stacked me-1"></i>
            Back to Materials
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3 bg-light text-success">
                        <i class="fa-solid fa-boxes-stacked fa-lg text-teal"></i>
                    </div>

                    <div>
                        <div class="text-muted small">Tracked Materials</div>
                        <div class="h3 mb-0">
                            <?= e((string) $trackedStockCount) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3 bg-light text-danger">
                        <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                    </div>

                    <div>
                        <div class="text-muted small">At or Below Reorder Level</div>
                        <div class="h3 mb-0">
                            <?= e((string) $lowStockCount) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters are outside the stock table card. -->
<div class="asset-filter-panel mb-4">
    <form method="GET" action="<?= e($baseStockUrl) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-6">
                <label for="stock-search" class="form-label">Search stock</label>
                <input
                    type="search"
                    id="stock-search"
                    name="search"
                    class="form-control"
                    placeholder="Search material code or name"
                    value="<?= e($filters['search']) ?>"
                >
            </div>

            <div class="col-6 col-lg-2">
                <label for="stock-per-page" class="form-label">Rows per page</label>
                <select id="stock-per-page" name="per_page" class="form-select">
                    <?php foreach ($allowedPageSizes as $size): ?>
                        <option
                            value="<?= $size ?>"
                            <?= $perPage === $size ? 'selected' : '' ?>
                        >
                            <?= $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>
                    Search
                </button>

                <a href="<?= e($baseStockUrl) ?>" class="btn btn-outline-secondary">
                    Reset filters
                </a>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-sm-6 col-xl-4">
                <label for="stock-status" class="form-label">Stock status</label>
                <select id="stock-status" name="status" class="form-select">
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="reorder" <?= $filters['status'] === 'reorder' ? 'selected' : '' ?>>Reorder required</option>
                    <option value="low" <?= $filters['status'] === 'low' ? 'selected' : '' ?>>Low stock</option>
                    <option value="in_stock" <?= $filters['status'] === 'in_stock' ? 'selected' : '' ?>>In stock</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-xl-4">
                <label for="stock-sort" class="form-label">Sort by</label>
                <select id="stock-sort" name="sort" class="form-select">
                    <option value="material_name" <?= $filters['sort'] === 'material_name' ? 'selected' : '' ?>>Material name</option>
                    <option value="material_code" <?= $filters['sort'] === 'material_code' ? 'selected' : '' ?>>Material code</option>
                    <option value="quantity" <?= $filters['sort'] === 'quantity' ? 'selected' : '' ?>>Available quantity</option>
                    <option value="minimum_stock_level" <?= $filters['sort'] === 'minimum_stock_level' ? 'selected' : '' ?>>Minimum level</option>
                    <option value="reorder_level" <?= $filters['sort'] === 'reorder_level' ? 'selected' : '' ?>>Reorder level</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-xl-4">
                <label for="stock-direction" class="form-label">Sort direction</label>
                <select id="stock-direction" name="direction" class="form-select">
                    <option value="asc" <?= $filters['direction'] === 'asc' ? 'selected' : '' ?>>Ascending</option>
                    <option value="desc" <?= $filters['direction'] === 'desc' ? 'selected' : '' ?>>Descending</option>
                </select>
            </div>
        </div>
    </form>
</div>

<!-- Stock table card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
            <h2 class="h5 mb-0">Current Stock</h2>

            <span class="text-muted small">
                <?= e((string) $totalStockCount) ?> stock record(s)
            </span>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($stocks)): ?>
            <div class="text-center py-5 px-3">
                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-warehouse"></i>
                </div>

                <h3 class="h5">No Stock Records Found</h3>

                <p class="text-muted mb-0">
                    No stock records match your search and filter criteria.
                    Try changing your filters or reset them to view all stock.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Material Code</th>
                            <th>Material</th>
                            <th class="text-end">Available Quantity</th>
                            <th class="text-end">Minimum Level</th>
                            <th class="text-end">Reorder Level</th>
                            <th class="text-center">Stock Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($stocks as $stock): ?>
                            <?php
                            $quantity = (float) $stock['quantity'];
                            $minimumLevel = (float) $stock['minimum_stock_level'];
                            $reorderLevel = (float) $stock['reorder_level'];

                            if ($quantity <= $reorderLevel) {
                                $statusLabel = 'Reorder Required';
                                $statusClass = 'bg-danger';
                            } elseif ($quantity <= $minimumLevel) {
                                $statusLabel = 'Low Stock';
                                $statusClass = 'bg-warning text-dark';
                            } else {
                                $statusLabel = 'In Stock';
                                $statusClass = 'bg-success';
                            }
                            ?>

                            <tr>
                                <td>
                                    <span class="fw-semibold">
                                        <?= e((string) $stock['material_code']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= e((string) $stock['material_name']) ?>
                                    </div>
                                </td>

                                <td class="text-end">
                                    <span class="fw-semibold">
                                        <?= e(number_format($quantity, 3)) ?>
                                    </span>
                                    <span class="text-muted small">
                                        <?= e((string) $stock['material_unit']) ?>
                                    </span>
                                </td>

                                <td class="text-end">
                                    <?= e(number_format($minimumLevel, 3)) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(number_format($reorderLevel, 3)) ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div class="text-muted small">
                    Showing
                    <?= e((string) (($page - 1) * $perPage + 1)) ?>–<?= e((string) min($page * $perPage, $totalStockCount)) ?>
                    of <?= e((string) $totalStockCount) ?> records
                </div>

                <nav aria-label="Stock pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($paginationUrl(max(1, $page - 1))) ?>"
                                <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                            >Previous</a>
                        </li>

                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                <a
                                    class="page-link"
                                    href="<?= e($paginationUrl($pageNumber)) ?>"
                                    <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>
                                ><?= e((string) $pageNumber) ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>"
                                <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                            >Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">Record Material Replenishment</h2>
    </div>

    <div class="card-body">
        <p class="text-muted">
            Record a replenishment for audit history. This will not change
            your department's available stock quantity.
        </p>

        <?php if (empty($replenishmentMaterials)): ?>
            <div class="alert alert-info mb-0">
                No active materials with stock records are available
                for your department.
            </div>
        <?php else: ?>
            <form id="replenishmentForm">
                <?= \App\Helpers\Csrf::field() ?>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="replenishment-material" class="form-label">
                            Material <span class="text-danger">*</span>
                        </label>
                            <select
                                id="replenishment-material"
                                name="material_id"
                                class="form-select"
                                required
                            >
                                <option value="">Select material</option>

                                <?php foreach ($replenishmentMaterials as $material): ?>
                                    <option value="<?= (int) $material['material_id'] ?>">
                                        <?= e((string) $material['material_code']) ?>
                                        —
                                        <?= e((string) $material['material_name']) ?>
                                        (<?= e((string) $material['material_unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="replenishment-quantity" class="form-label">
                            Replenished Quantity
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="number"
                            id="replenishment-quantity"
                            name="quantity"
                            class="form-control"
                            min="0.001"
                            step="0.001"
                            required
                            placeholder="Enter quantity"
                        >
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="replenishment-date" class="form-label">
                            Replenishment Date and Time
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            id="replenishment-date"
                            name="replenished_at"
                            class="form-control"
                            value="<?= e(date('Y-m-d\TH:i')) ?>"
                            required
                        >
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="replenishment-reference" class="form-label">
                            Reference Number
                        </label>

                        <input
                            type="text"
                            id="replenishment-reference"
                            name="reference_number"
                            class="form-control"
                            maxlength="100"
                            placeholder="Optional reference"
                        >
                    </div>

                    <div class="col-12">
                        <label for="replenishment-notes" class="form-label">
                            Notes
                        </label>

                        <textarea
                            id="replenishment-notes"
                            name="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Optional replenishment notes"
                        ></textarea>
                    </div>
                </div>

                <hr class="my-4">

                <div class="d-flex justify-content-end">
                    <button
                        type="submit"
                        id="replenishmentSubmit"
                        class="btn btn-primary"
                    >
                        <i class="fa-solid fa-clipboard-list me-1"></i>
                        Save History Record
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('replenishmentForm');

    if (!form) {
        return;
    }

    const submitButton = document.getElementById('replenishmentSubmit');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        const confirmed = await Swal.fire({
            icon: 'question',
            title: 'Save Replenishment History?',
            text: 'This will create an audit history record only. Stock balances will not change.',
            showCancelButton: true,
            confirmButtonText: 'Save Record',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#007472'
        });

        if (!confirmed.isConfirmed) {
            return;
        }

        submitButton.disabled = true;

        const originalButtonText = submitButton.innerHTML;

        submitButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const response = await fetch(
                <?= json_encode(
                    base_url('/public/api/material_replenishment_create.php'),
                    JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_HEX_AMP
                ) ?>,
                {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || 'Unable to save replenishment history.'
                );
            }

            await Swal.fire({
                icon: 'success',
                title: 'History Recorded',
                text: data.message,
                confirmButtonColor: '#007472'
            });

            window.location.reload();

        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'Unable to Save Record',
                text: error.message || 'Please try again.',
                confirmButtonColor: '#007472'
            });
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        }
    });
});
</script>

<div class="card border-0 shadow-sm mb-4" id="replenishment-history">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Replenishment History</h2>
        <span class="text-muted small">
            <?= number_format($replenishmentTotal) ?> record(s)
        </span>
    </div>

    <div class="card-body">
        <form
            method="GET"
            action="<?= e(base_url('/public/materials/stock.php#replenishment-history')) ?>"
            class="row g-3 mb-4"
        >
            <input
                type="hidden"
                name="search"
                value="<?= e((string) ($_GET['search'] ?? '')) ?>"
            >
            <input
                type="hidden"
                name="category_id"
                value="<?= e((string) ($_GET['category_id'] ?? '')) ?>"
            >
            <input
                type="hidden"
                name="status"
                value="<?= e((string) ($_GET['status'] ?? '')) ?>"
            >
            <input
                type="hidden"
                name="sort"
                value="<?= e((string) ($_GET['sort'] ?? 'name')) ?>"
            >
            <input
                type="hidden"
                name="direction"
                value="<?= e((string) ($_GET['direction'] ?? 'asc')) ?>"
            >
            <input
                type="hidden"
                name="page"
                value="<?= (int) ($_GET['page'] ?? 1) ?>"
            >
            <input
                type="hidden"
                name="per_page"
                value="<?= (int) ($_GET['per_page'] ?? 10) ?>"
            >

            <div class="col-12 col-md-4">
                <label for="replenishment-search" class="form-label">
                    Search
                </label>
                <input
                    type="search"
                    id="replenishment-search"
                    name="replenishment_search"
                    class="form-control"
                    value="<?= e($replenishmentFilters['search']) ?>"
                    placeholder="Material, code, reference..."
                >
            </div>

            <div class="col-12 col-md-3">
                <label for="replenishment-date-from" class="form-label">
                    Date From
                </label>
                <input
                    type="date"
                    id="replenishment-date-from"
                    name="replenishment_date_from"
                    class="form-control"
                    value="<?= e($replenishmentFilters['date_from']) ?>"
                >
            </div>

            <div class="col-12 col-md-3">
                <label for="replenishment-date-to" class="form-label">
                    Date To
                </label>
                <input
                    type="date"
                    id="replenishment-date-to"
                    name="replenishment_date_to"
                    class="form-control"
                    value="<?= e($replenishmentFilters['date_to']) ?>"
                >
            </div>

            <div class="col-12 col-md-2">
                <label for="replenishment-per-page" class="form-label">
                    Rows
                </label>
                <select
                    id="replenishment-per-page"
                    name="replenishment_per_page"
                    class="form-select"
                >
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option
                            value="<?= $size ?>"
                            <?= $replenishmentPerPage === $size ? 'selected' : '' ?>
                        >
                            <?= $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter me-1"></i>
                    Filter
                </button>

                <a
                    href="<?= e(base_url('/public/materials/stock.php#replenishment-history')) ?>"
                    class="btn btn-outline-secondary"
                    title="Clear replenishment filters"
                    aria-label="Clear replenishment filters"
                >
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>

        <?php if (empty($replenishmentHistory)): ?>
            <div class="text-center py-5">
                <i class="fa-solid fa-clipboard-list fa-2x text-muted mb-3"></i>

                <h3 class="h6">
                    <?= $replenishmentFilters['search'] !== ''
                        || $replenishmentFilters['date_from'] !== ''
                        || $replenishmentFilters['date_to'] !== ''
                            ? 'No matching replenishment records'
                            : 'No replenishment history yet' ?>
                </h3>

                <p class="text-muted mb-0">
                    Replenishment records will appear here once they are saved.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Quantity</th>
                            <th>Replenished At</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>Recorded By</th>
                            <th>Logged At</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($replenishmentHistory as $record): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e((string) $record['material_name']) ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= e((string) $record['material_code']) ?>
                                    </small>
                                </td>

                                <td class="text-nowrap">
                                    <?= number_format(
                                        (float) $record['quantity'],
                                        3
                                    ) ?>
                                    <?= e((string) $record['material_unit']) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(date(
                                        'M d, Y h:i A',
                                        strtotime((string) $record['replenished_at'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= e((string) ($record['reference_number'] ?? '—')) ?>
                                </td>

                                <td>
                                    <?= e((string) ($record['notes'] ?? '—')) ?>
                                </td>

                                <td>
                                    <?= e((string) ($record['recorded_by_name'] ?: 'Unknown')) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(date(
                                        'M d, Y h:i A',
                                        strtotime((string) $record['created_at'])
                                    )) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $replenishmentStart =
                (($replenishmentPageNumber - 1) * $replenishmentPerPage) + 1;

            $replenishmentEnd = min(
                $replenishmentStart + count($replenishmentHistory) - 1,
                $replenishmentTotal
            );

            $replenishmentQuery = [
                'replenishment_search' => $replenishmentFilters['search'],
                'replenishment_date_from' => $replenishmentFilters['date_from'],
                'replenishment_date_to' => $replenishmentFilters['date_to'],
                'replenishment_per_page' => $replenishmentPerPage,
            ];

            $previousReplenishmentUrl = base_url(
                '/public/materials/stock.php?'
                . http_build_query(
                    array_merge(
                        $replenishmentQuery,
                        ['replenishment_page' => max(1, $replenishmentPageNumber - 1)]
                    )
                )
                . '#replenishment-history'
            );

            $nextReplenishmentUrl = base_url(
                '/public/materials/stock.php?'
                . http_build_query(
                    array_merge(
                        $replenishmentQuery,
                        ['replenishment_page' => min(
                            $replenishmentTotalPages,
                            $replenishmentPageNumber + 1
                        )]
                    )
                )
                . '#replenishment-history'
            );
            ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <small class="text-muted">
                    Showing <?= number_format($replenishmentStart) ?>
                    to <?= number_format($replenishmentEnd) ?>
                    of <?= number_format($replenishmentTotal) ?> records
                </small>

                <div class="d-flex align-items-center gap-2">
                    <a
                        href="<?= e($previousReplenishmentUrl) ?>"
                        class="btn btn-outline-secondary btn-sm <?= $replenishmentPageNumber <= 1 ? 'disabled' : '' ?>"
                        <?= $replenishmentPageNumber <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        Previous
                    </a>

                    <span class="small text-muted">
                        Page <?= number_format($replenishmentPageNumber) ?>
                        of <?= number_format(max(1, $replenishmentTotalPages)) ?>
                    </span>

                    <a
                        href="<?= e($nextReplenishmentUrl) ?>"
                        class="btn btn-outline-secondary btn-sm <?= $replenishmentPageNumber >= $replenishmentTotalPages ? 'disabled' : '' ?>"
                        <?= $replenishmentPageNumber >= $replenishmentTotalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        Next
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/app_footer.php';
?>
