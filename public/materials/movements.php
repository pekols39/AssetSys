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

if ($authorization->departmentId() === null) {
    http_response_code(403);
    exit('Your account must be assigned to a department.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'movement_type' => (string) ($_GET['movement_type'] ?? 'all'),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$page = $page === false ? 1 : $page;

$perPage = filter_var(
    $_GET['per_page'] ?? 10,
    FILTER_VALIDATE_INT
);

$perPage = in_array($perPage, [10, 25, 50, 100], true)
    ? $perPage
    : 10;

$stockService = new StockService();

try {
    $result = $stockService->getDepartmentMovementPage(
        $filters,
        $page,
        $perPage
    );
} catch (\RuntimeException $exception) {
    http_response_code(403);
    exit('Unable to load stock movement history. Please verify your department access.');
}

$movements = $result['items'];
$total = $result['total'];
$page = $result['page'];
$totalPages = $result['total_pages'];

$pageTitle = 'Stock Movement History';

require_once dirname(__DIR__) . '/includes/app_header.php';

$e = static fn(mixed $value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES,
    'UTF-8'
);

$queryForPage = static function (int $targetPage) use ($filters, $perPage): string {
    return http_build_query([
        'search' => $filters['search'],
        'movement_type' => $filters['movement_type'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'per_page' => $perPage,
        'page' => $targetPage,
    ]);
};

$movementLabels = [
    'in' => 'Stock In',
    'out' => 'Stock Out',
    'return' => 'Return',
    'adjustment' => 'Adjustment',
];

$startRecord = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$endRecord = min($page * $perPage, $total);
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="text-muted small mb-1">Materials &amp; Stock</div>
            <h1 class="h3 mb-1">Stock Movement History</h1>
            <p class="text-muted mb-0">
                Review stock receipts, issues, returns, and adjustments for your department.
            </p>
        </div>

        <a href="<?= $e(base_url('/public/materials/stock.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>
            Back to Stock
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="<?= $e(base_url('/public/materials/movements.php')) ?>">
                <div class="row g-3 align-items-end">

                    <div class="col-12 col-lg-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" class="form-control" id="search" name="search"
                            value="<?= $e($filters['search']) ?>" placeholder="Material, reference, recipient...">
                    </div>

                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="movement_type" class="form-label">Movement Type</label>
                        <select class="form-select" id="movement_type" name="movement_type">
                            <option value="all" <?= $filters['movement_type'] === 'all' ? 'selected' : '' ?>>
                                All Types
                            </option>
                            <option value="in" <?= $filters['movement_type'] === 'in' ? 'selected' : '' ?>>
                                Stock In
                            </option>
                            <option value="out" <?= $filters['movement_type'] === 'out' ? 'selected' : '' ?>>
                                Stock Out
                            </option>
                            <option value="return" <?= $filters['movement_type'] === 'return' ? 'selected' : '' ?>>
                                Return
                            </option>
                            <option value="adjustment" <?= $filters['movement_type'] === 'adjustment' ? 'selected' : '' ?>>
                                Adjustment
                            </option>
                        </select>
                    </div>

                    <div class="col-6 col-sm-3 col-lg-2">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                            value="<?= $e($filters['date_from']) ?>">
                    </div>

                    <div class="col-6 col-sm-3 col-lg-2">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                            value="<?= $e($filters['date_to']) ?>">
                    </div>

                    <div class="col-6 col-lg-1">
                        <label for="per_page" class="form-label">Rows</label>
                        <select class="form-select" id="per_page" name="per_page">
                            <?php foreach ([10, 25, 50, 100] as $size): ?>
                                <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>>
                                    <?= $size ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-lg-1 d-grid">
                        <button type="submit" class="btn movement-filter-btn">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>

                </div>

                <div class="mt-3">
                    <a class="btn btn-sm btn-outline-secondary"
                        href="<?= $e(base_url('/public/materials/movements.php')) ?>">
                        Clear Filters
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h5 mb-0">Movement Records</h2>
            <span class="text-muted small">
                Showing <?= $e($startRecord) ?>–<?= $e($endRecord) ?>
                of <?= $e($total) ?> record(s)
            </span>
        </div>

        <div class="card-body p-0">
            <?php if ($movements === []): ?>
                <div class="text-center py-5 px-3">
                    <div class="text-muted fs-1 mb-3">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <h3 class="h5">No Stock Movements Found</h3>
                    <p class="text-muted mb-0">
                        No movement records match the selected search and filters.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Material</th>
                                <th>Type</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Balance After</th>
                                <th>Reference</th>
                                <th>Recipient / Vendor</th>
                                <th>Recorded By</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <?php
                                $type = (string) ($movement['movement_type'] ?? '');
                                $badgeClass = match ($type) {
                                    'in' => 'text-bg-success',
                                    'out' => 'text-bg-primary',
                                    'return' => 'text-bg-info',
                                    'adjustment' => 'text-bg-warning',
                                    default => 'text-bg-secondary',
                                };

                                $quantityDelta = (float) ($movement['quantity_delta'] ?? 0);
                                $quantityPrefix = $quantityDelta > 0 ? '+' : '';
                                ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <?= $e($movement['moved_at'] ?? '') ?>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">
                                            <?= $e($movement['material_name'] ?? 'Unknown material') ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= $e($movement['material_code'] ?? '') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge <?= $e($badgeClass) ?>">
                                            <?= $e($movementLabels[$type] ?? ucfirst($type)) ?>
                                        </span>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= $e($quantityPrefix . number_format($quantityDelta, 3)) ?>
                                        <span class="text-muted small">
                                            <?= $e($movement['material_unit'] ?? '') ?>
                                        </span>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= $e(number_format((float) ($movement['balance_after'] ?? 0), 3)) ?>
                                        <span class="text-muted small">
                                            <?= $e($movement['material_unit'] ?? '') ?>
                                        </span>
                                    </td>

                                    <td><?= $e($movement['reference_number'] ?? '—') ?></td>

                                    <td>
                                        <?php
                                        $recipient = trim((string) ($movement['recipient_name'] ?? ''));
                                        $vendor = trim((string) ($movement['vendor_name'] ?? ''));
                                        ?>
                                        <?php if ($recipient !== ''): ?>
                                            <div><?= $e($recipient) ?></div>
                                        <?php endif; ?>
                                        <?php if ($vendor !== ''): ?>
                                            <div class="text-muted small">
                                                Vendor: <?= $e($vendor) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($recipient === '' && $vendor === ''): ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= $e($movement['recorded_by_name'] ?? '—') ?></td>

                                    <td>
                                        <?php
                                        $details = array_filter([
                                            trim((string) ($movement['purpose'] ?? '')),
                                            trim((string) ($movement['reason'] ?? '')),
                                            trim((string) ($movement['notes'] ?? '')),
                                        ]);
                                        ?>
                                        <?php if ($details !== []): ?>
                                            <span class="d-inline-block"
                                                style="min-width: 160px; max-width: 280px; white-space: normal;"
                                                title="<?= $e(implode(' | ', $details)) ?>">
                                                <?= $e(implode(' — ', $details)) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
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
                        Page <?= $e($page) ?> of <?= $e($totalPages) ?>
                    </div>

                    <nav aria-label="Stock movement pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <?php if ($page > 1): ?>
                                    <a class="page-link"
                                        href="<?= $e(base_url('/public/materials/movements.php?' . $queryForPage($page - 1))) ?>">
                                        Previous
                                    </a>
                                <?php else: ?>
                                    <span class="page-link">Previous</span>
                                <?php endif; ?>
                            </li>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <?php if ($page < $totalPages): ?>
                                    <a class="page-link"
                                        href="<?= $e(base_url('/public/materials/movements.php?' . $queryForPage($page + 1))) ?>">
                                        Next
                                    </a>
                                <?php else: ?>
                                    <span class="page-link">Next</span>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>