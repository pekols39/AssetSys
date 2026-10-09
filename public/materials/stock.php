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
    exit(
        '403 Forbidden: Asset Manager or Asset Custodian access required.'
    );
}

$stockService = new StockService();

$stocks = $stockService->getDepartmentStock();
$lowStockItems = $stockService->getLowStock();

$lowStockCount = count($lowStockItems);
$trackedStockCount = count($stocks);

$pageTitle = 'Department Material Stock';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-column flex-md-row
            justify-content-between align-items-md-center
            gap-3 mb-4">

    <div>
        <div class="text-muted small mb-1">
            Materials &amp; Stock
        </div>

        <h1 class="h3 mb-1">
            Department Material Stock
        </h1>

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
                        <i class="fa-solid fa-boxes-stacked fa-lg"></i>
                    </div>

                    <div>
                        <div class="text-muted small">
                            Tracked Materials
                        </div>

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
                        <div class="text-muted small">
                            At or Below Reorder Level
                        </div>

                        <div class="h3 mb-0">
                            <?= e((string) $lowStockCount) ?>
                        </div>
                    </div>

                </div>

            </div>
        </div>

    </div>

</div>


<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <div class="d-flex flex-column flex-sm-row
                    justify-content-between align-items-sm-center
                    gap-2">

            <h2 class="h5 mb-0">
                Current Stock
            </h2>

            <span class="text-muted small">
                <?= e((string) $trackedStockCount) ?> stock record(s)
            </span>

        </div>

    </div>


    <div class="card-body p-0">

        <?php if (empty($stocks)): ?>

            <div class="text-center py-5 px-3">

                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-warehouse"></i>
                </div>

                <h3 class="h5">
                    No Stock Records Yet
                </h3>

                <p class="text-muted mb-0">
                    No material stock has been recorded for your department.
                    Once stock is received, the balances will appear here.
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

</div>


<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';

?>