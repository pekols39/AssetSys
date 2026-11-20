<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use App\Services\AuthorizationService;
use App\Services\ReferenceDataService;

AuthMiddleware::handle();


$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

$assetService = new AssetService();

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to view the asset register.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'category_id' => (string) ($_GET['category_id'] ?? ''),
    'status_id' => (string) ($_GET['status_id'] ?? ''),
    'condition_id' => (string) ($_GET['condition_id'] ?? ''),
    'location_id' => (string) ($_GET['location_id'] ?? ''),
    'finance_owner_department' => trim((string) ($_GET['finance_owner_department'] ?? '')),
    'finance_main_location' => trim((string) ($_GET['finance_main_location'] ?? '')),
    'finance_status' => trim((string) ($_GET['finance_status'] ?? '')),
    'finance_supplier' => trim((string) ($_GET['finance_supplier'] ?? '')),
    'sort' => (string) ($_GET['sort'] ?? 'created_at'),
    'direction' => (string) ($_GET['direction'] ?? 'desc'),
];

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

$page = $page !== false ? max(1, $page) : 1;


$perPage = filter_var($_GET['per_page'] ?? 10, FILTER_VALIDATE_INT);
$perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;


$referenceData = new ReferenceDataService();

$categories = $referenceData->getAssetCategories();
$statuses = $referenceData->getAssetStatuses();
$conditions = $referenceData->getAssetConditions();
$locations = $referenceData->getLocations();

$result = $assetService->getPage(
    $filters,
    null,
    $page,
    $perPage
);

$assets = $result['items'];
$totalAssets = $result['total'];
$currentPage = $result['page'];
$totalPages = $result['total_pages'];


$pageTitle = 'Assets';
$pageStylesheet = 'css/index.css';
$pageScript = 'js/index.js';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>


<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>

        <div class="text-muted small mb-1">
            Inventory Tracker
        </div>

        <h1 class="h3 mb-1">
            Assets
        </h1>

        <p class="text-muted mb-0">
            Manage and monitor company inventory.
        </p>

    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">

        <a
            href="<?= e(base_url('/public/assets/export_excel.php?' . http_build_query($_GET))) ?>"
            class="btn btn-outline-success"
        >
            <i class="fa-solid fa-file-excel me-1"></i>
            Export Excel
        </a>

        <a
            href="<?= e(base_url('/public/assets/export_pdf.php?' . http_build_query($_GET))) ?>"
            class="btn btn-outline-danger"
        >
            <i class="fa-solid fa-file-pdf me-1"></i>
            Export PDF
        </a>

        <a href="<?= e(base_url('/public/assets/import_history.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-clock-rotate-left me-1"></i>
            Import History
        </a>

        <a href="<?= e(base_url('/public/assets/import.php')) ?>" class="btn btn-outline-primary">
            <i class="fa-solid fa-file-import me-1"></i>
            Import Asset Excel
        </a>

        <a href="<?= e(base_url('/public/assets/create.php')) ?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i>
            Add Asset
        </a>

    </div>

</div>

<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

            <div>
                <h2 class="h5 mb-0">
                    Asset Register
                </h2>

                <div class="text-muted small">
                    <?= e((string) $totalAssets) ?>
                    asset(s)
                </div>
            </div>

        </div>

    </div>

    <div class="card-body">
        <div class="asset-filter-panel">
            <form method="GET" action="<?= htmlspecialchars(base_url('/public/assets/index.php')) ?>">
                <input type="hidden" name="sort"
                    value="<?= htmlspecialchars($filters['sort']) ?>">
                <input type="hidden" name="direction"
                    value="<?= htmlspecialchars($filters['direction']) ?>">

                <div class="row g-3 align-items-end">
                    <!-- Primary search -->
                    <div class="col-12 col-lg-6">
                        <label for="asset-search" class="form-label">
                            Search assets
                        </label>
                        <input
                            type="search"
                            id="asset-search"
                            name="search"
                            class="form-control"
                            placeholder="Item ID, Item Name, FFE Number, Code, Location, Owner / Department..."
                            value="<?= htmlspecialchars($filters['search']) ?>"
                        >
                    </div>

                    <!-- Rows per page -->
                    <div class="col-6 col-lg-2">
                        <label for="per-page" class="form-label">
                            Rows per page
                        </label>
                        <select id="per-page" name="per_page" class="form-select">
                            <?php foreach ([10, 25, 50, 100] as $size): ?>
                                <option value="<?= $size ?>"
                                    <?= $perPage === $size ? 'selected' : '' ?>>
                                    <?= $size ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Search actions -->
                    <div class="col-6 col-lg-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="<?= htmlspecialchars(base_url('/public/assets/index.php')) ?>"
                        class="btn btn-outline-secondary">
                            Reset filters
                        </a>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="category-filter" class="form-label">Category</label>
                        <select id="category-filter" name="category_id"
                                class="form-select">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"
                                    <?= (string) $filters['category_id'] ===
                                        (string) $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="status-filter" class="form-label">Operational Status</label>
                        <select id="status-filter" name="status_id" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= (int) $status['id'] ?>"
                                    <?= (string) $filters['status_id'] ===
                                        (string) $status['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="condition-filter" class="form-label">Condition</label>
                        <select id="condition-filter" name="condition_id"
                                class="form-select">
                            <option value="">All conditions</option>
                            <?php foreach ($conditions as $condition): ?>
                                <option value="<?= (int) $condition['id'] ?>"
                                    <?= (string) $filters['condition_id'] ===
                                        (string) $condition['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($condition['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="location-filter" class="form-label">Current Physical Location (AssetSys)</label>
                        <select id="location-filter" name="location_id" class="form-select">
                            <option value="">All physical locations</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int) $location['id'] ?>" <?= (string) $filters['location_id'] === (string) $location['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($location['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="finance-owner-filter" class="form-label">Owner / Department</label>
                        <input id="finance-owner-filter" name="finance_owner_department" class="form-control" value="<?= e($filters['finance_owner_department']) ?>" placeholder="Filter owner / department">
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="finance-main-location-filter" class="form-label">Location (Main)</label>
                        <input id="finance-main-location-filter" name="finance_main_location" class="form-control" value="<?= e($filters['finance_main_location']) ?>" placeholder="Filter main location">
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="finance-status-filter" class="form-label">Finance Status</label>
                        <input id="finance-status-filter" name="finance_status" class="form-control" value="<?= e($filters['finance_status']) ?>" placeholder="Filter finance status">
                    </div>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <label for="finance-supplier-filter" class="form-label">Supplier</label>
                        <input id="finance-supplier-filter" name="finance_supplier" class="form-control" value="<?= e($filters['finance_supplier']) ?>" placeholder="Filter supplier">
                    </div>
                </div>
            </form>
        </div>

        <?php if (isset($_GET['bulk_qr']) && $_GET['bulk_qr'] === '1'): ?>
            <div class="alert alert-info" role="status">
                <strong>QR generation completed.</strong>
                New QR identities: <?= (int) ($_GET['created'] ?? 0) ?>.
                Existing QR identities reused: <?= (int) ($_GET['reused'] ?? 0) ?>.
                Skipped: <?= (int) ($_GET['skipped'] ?? 0) ?>.
                Failed: <?= (int) ($_GET['failed'] ?? 0) ?>.
                No print jobs were created. Use the separate Print QR Labels action when ready.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['bulk_print']) && $_GET['bulk_print'] === '1'): ?>
            <div class="alert alert-info" role="status">
                <strong>Print batch queued.</strong>
                Print jobs queued: <?= (int) ($_GET['queued'] ?? 0) ?>.
                Skipped (missing or inactive QR identity): <?= (int) ($_GET['skipped'] ?? 0) ?>.
                Failed: <?= (int) ($_GET['failed'] ?? 0) ?>.
                The NIIMBOT agent will process the selected labels one at a time.
            </div>
        <?php endif; ?>

        <?php if (empty($assets)): ?>

            <div class="text-center py-5">

                <div class="text-muted fs-1 mb-3">
                    <i class="fa-solid fa-box-open"></i>
                </div>

                <h3 class="h5">
                    No assets found
                </h3>

                <p class="text-muted mb-4">
                    No assets have been registered yet.
                </p>

                <a href="<?= e(base_url('/public/assets/create.php')) ?>" class="btn btn-primary">
                    <i class="fa-solid fa-plus me-1"></i>
                    Add First Asset
                </a>

            </div>

        <?php else: ?>

            <form id="bulkQrForm" data-total-filtered="<?= (int) $totalAssets ?>" method="POST" action="<?= e(base_url('/public/assets/bulk-qr-generate.php')) ?>" class="border rounded p-3 mb-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="selection_mode" id="bulkSelectionMode" value="selected">
                <?php foreach ($filters as $filterKey => $filterValue): ?>
                    <input type="hidden" name="filters[<?= e($filterKey) ?>]" value="<?= e((string) $filterValue) ?>">
                <?php endforeach; ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <div class="fw-semibold">Bulk QR management</div>
                        <div class="small text-muted" id="bulkSelectionSummary">Select assets to generate QR identities or print existing QR labels. These are separate actions.</div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small text-muted" id="bulkSelectedCount">0 selected</span>
                        <button type="submit" class="btn btn-primary" id="bulkQrGenerateSubmit" value="generate" formaction="<?= e(base_url('/public/assets/bulk-qr-generate.php')) ?>" disabled>
                            <i class="fa-solid fa-qrcode me-1"></i>
                            Generate QR Codes
                        </button>
                        <button type="submit" class="btn btn-outline-primary" id="bulkQrPrintSubmit" value="print" formaction="<?= e(base_url('/public/assets/bulk-qr-print.php')) ?>" disabled>
                            <i class="fa-solid fa-print me-1"></i>
                            Print QR Labels
                        </button>
                    </div>
                </div>
            </form>

            <div class="table-responsive" id="assetsTableScroll">

                <table id="assetsTable" class="table table-hover align-middle mb-0 asset-table">

                    <thead>
                        <tr>
                            <th class="text-center"><input type="checkbox" class="form-check-input" id="selectAllFilteredAssets" aria-label="Select all filtered assets across all pages" title="Select all filtered assets across all pages"></th>
                            <th>Asset Code</th>
                            <th>QR Status</th>
                            <th>Location (Main)</th>
                            <th>Owner / Department</th>
                            <th>Category</th>
                            <th>Item ID</th>
                            <th>Item Name</th>
                            <th>FFE Number</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th>Supplier</th>
                            <th>PO Number</th>
                            <th>Price</th>
                            <th>Date Purchase</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($assets as $asset): ?>
                            <?php
                            $financeValue = static function (mixed $value): string {
                                $value = trim((string) ($value ?? ''));
                                return $value === '' || strtolower($value) === 'null' ? '—' : $value;
                            };
                            ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input asset-row-select" value="<?= (int) $asset['id'] ?>" aria-label="Select asset <?= e($asset['asset_code'] ?? '') ?>"></td>
                                <td><span class="asset-code"><?= e($financeValue($asset['asset_code'] ?? null)) ?></span></td>
                                <td>
                                    <?php if ((int) ($asset['qr_is_active'] ?? 0) === 1): ?>
                                        <span class="badge bg-success">Generated</span>
                                    <?php elseif (isset($asset['qr_code_id'])): ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">Not generated</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($financeValue($asset['finance_main_location'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_owner_department'] ?? null)) ?></td>
                                <td><?= e($asset['category_name'] ?? '—') ?></td>
                                <td><?= e($financeValue($asset['finance_item_id'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_item_name'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_ffe_number'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_code'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_status'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_supplier'] ?? null)) ?></td>
                                <td><?= e($financeValue($asset['finance_po_number'] ?? null)) ?></td>
                                <td class="text-nowrap"><?= e($financeValue($asset['finance_price_text'] ?? null)) ?></td>
                                <td class="text-nowrap"><?= e($financeValue($asset['finance_date_purchase_text'] ?? null)) ?></td>
                                <td class="text-center">
                                    <a href="<?= e(base_url('/public/assets/view.php?id=' . $asset['id'])) ?>"
                                        class="btn btn-sm asset-view-button" title="View asset">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>
            <div class="asset-horizontal-scrollbar" id="assetsHorizontalScrollbar" aria-label="Scroll asset table horizontally">
                <div class="asset-horizontal-scrollbar-inner" id="assetsHorizontalScrollbarInner"></div>
            </div>

        <?php endif; ?>

                <?php
        $startItem = $totalAssets > 0
            ? (($currentPage - 1) * $perPage) + 1
            : 0;

        $endItem = min($currentPage * $perPage, $totalAssets);

        $paginationUrl = static function (int $targetPage): string {
            $query = $_GET;
            $query['page'] = $targetPage;

            return base_url(
                '/public/assets/index.php?' . http_build_query($query)
            );
        };
        ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4">

            <div class="text-muted small">
                Showing <?= e((string) $startItem) ?>
                to <?= e((string) $endItem) ?>
                of <?= e((string) $totalAssets) ?> assets
            </div>

            <?php if ($totalPages > 1): ?>

                <nav aria-label="Asset register pagination">
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <?php if ($currentPage > 1): ?>
                                <a class="page-link" href="<?= e($paginationUrl($currentPage - 1)) ?>">
                                    Previous
                                </a>
                            <?php else: ?>
                                <span class="page-link">Previous</span>
                            <?php endif; ?>
                        </li>

                        <?php
                        $firstPage = max(1, $currentPage - 2);
                        $lastPage = min($totalPages, $currentPage + 2);
                        ?>

                        <?php if ($firstPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= e($paginationUrl(1)) ?>">1</a>
                            </li>
                            <?php if ($firstPage > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">…</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($pageNumber = $firstPage; $pageNumber <= $lastPage; $pageNumber++): ?>
                            <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>">
                                <?php if ($pageNumber === $currentPage): ?>
                                    <span class="page-link" aria-current="page">
                                        <?= e((string) $pageNumber) ?>
                                    </span>
                                <?php else: ?>
                                    <a class="page-link" href="<?= e($paginationUrl($pageNumber)) ?>">
                                        <?= e((string) $pageNumber) ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if ($lastPage < $totalPages): ?>
                            <?php if ($lastPage < $totalPages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">…</span>
                                </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= e($paginationUrl($totalPages)) ?>">
                                    <?= e((string) $totalPages) ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <?php if ($currentPage < $totalPages): ?>
                                <a class="page-link" href="<?= e($paginationUrl($currentPage + 1)) ?>">
                                    Next
                                </a>
                            <?php else: ?>
                                <span class="page-link">Next</span>
                            <?php endif; ?>
                        </li>

                    </ul>
                </nav>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';