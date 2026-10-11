<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\StockService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (
    !$authorization->isAssetManager()
    && !$authorization->isAssetCustodian()
) {
    http_response_code(403);
    exit('403 Forbidden: Asset Manager or Asset Custodian access required.');
}

$stockService = new StockService();

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

<?php
require_once dirname(__DIR__) . '/includes/app_footer.php';
?>
