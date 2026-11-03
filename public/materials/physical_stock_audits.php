<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\PhysicalStockAuditService;

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

$auditService = new PhysicalStockAuditService();

$historyAuditType = (string) ($_GET['audit_type'] ?? '');

if (!in_array($historyAuditType, ['', 'consumable', 'returnable'], true)) {
    $historyAuditType = '';
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = (int) ($_GET['per_page'] ?? 10);

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}

$candidates = $authorization->isPropertyCustodian()
    ? $auditService->getCandidates('consumable')
    : [];

$auditResult = $auditService->getPaginatedAudits(
    $search,
    $status,
    $historyAuditType,
    $page,
    $perPage
);

$audits = $auditResult['data'];
$totalAudits = $auditResult['total'];
$page = $auditResult['page'];
$totalPages = $auditResult['total_pages'];

$pageTitle = 'Physical Stock Audit';

require_once dirname(__DIR__) . '/includes/app_header.php';

$baseUrl = base_url('/public/materials/physical_stock_audits.php');

$historyQuery = [
    'audit_type' => $historyAuditType,
    'search' => $search,
    'status' => $status,
    'per_page' => $perPage,
];

$paginationUrl = static function (int $targetPage) use ($baseUrl, $historyQuery): string {
    return $baseUrl . '?' . http_build_query(
        array_merge($historyQuery, ['page' => $targetPage])
    ) . '#audit-history';
};

$today = date('Y-m-d\TH:i');

?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Materials &amp; Stock</div>
        <h1 class="h3 mb-1">Physical Stock Audit</h1>
        <p class="text-muted mb-0">
            Record consumable material counts and maintain an organization-wide
            history of physical count discrepancies.
        </p>
    </div>

    <a href="<?= e(base_url('/public/materials/stock.php')) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>
        Back to Materials Stock
    </a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-1"></i>
    Physical counts are recorded for audit purposes only.
    Recording a count does not change material stock quantities
    or create stock movements.
</div>

<?php if ($authorization->isPropertyCustodian()): ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Record Consumable Physical Count</h2>
        </div>

        <div class="card-body">
            <form id="physicalStockAuditForm">
                <?= \App\Helpers\Csrf::field() ?>

                <div class="row g-3">

                    <div class="col-12 col-md-6">
                        <label for="audit-date" class="form-label">
                            Audit Date and Time <span class="text-danger">*</span>
                        </label>
                        <input type="datetime-local" id="audit-date" name="audit_date" class="form-control"
                            value="<?= e($today) ?>" required>
                    </div>

                    <div class="col-12">
                        <label for="audit-notes" class="form-label">
                            Audit Notes
                        </label>
                        <textarea id="audit-notes" name="audit_notes" class="form-control" rows="2" maxlength="2000"
                            placeholder="Optional notes about this physical audit"></textarea>
                    </div>
                </div>

                <hr class="my-4">

                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                        <h3 class="h6 mb-1">Items to Audit</h3>
                        <p class="text-muted small mb-0">
                            Enter the actual physical quantity for each selected item.
                        </p>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" id="audit-include-all" name="include_all" value="1" class="form-check-input">
                        <label for="audit-include-all" class="form-check-label">
                            Include all eligible items
                        </label>
                    </div>
                </div>

                <?php if (empty($candidates)): ?>
                    <div class="alert alert-warning mb-0">
                        There are no active consumable materials available for physical counting.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center">Select</th>
                                    <th>Item</th>
                                    <th class="text-end">System Quantity</th>
                                    <th class="text-end">Physical Quantity</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($candidates as $candidate): ?>
                                    <?php
                                    $itemId = (int) $candidate['material_id'];

                                    $systemQuantity = (float) $candidate['system_quantity'];

                                    $itemName = (string) $candidate['material_name'];
                                    $itemCode = (string) $candidate['material_code'];
                                    $itemUnit = (string) $candidate['material_unit'];

                                    $inputName = 'items[' . $itemId . '][material_id]';
                                    ?>

                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input audit-item-select" value="<?= $itemId ?>"
                                                data-item-id="<?= $itemId ?>" aria-label="Select <?= e($itemName) ?>">
                                        </td>

                                        <td>
                                            <div class="fw-semibold">
                                                <?= e($itemCode) ?> — <?= e($itemName) ?>
                                            </div>

                                            <div class="text-muted small">
                                                <?= e($itemUnit) ?>
                                            </div>
                                        </td>

                                        <td class="text-end">
                                            <?= e(number_format($systemQuantity, 3)) ?>
                                        </td>

                                        <td class="text-end" style="min-width: 150px;">
                                            <input type="hidden" name="<?= e($inputName) ?>" value="<?= $itemId ?>" disabled
                                                class="audit-item-field">

                                            <input type="number" name="items[<?= $itemId ?>][physical_quantity]"
                                                class="form-control form-control-sm text-end audit-item-field audit-physical-quantity"
                                                min="0" step="0.001" placeholder="0.000" required disabled>
                                        </td>

                                        <td style="min-width: 180px;">
                                            <input type="text" name="items[<?= $itemId ?>][item_notes]"
                                                class="form-control form-control-sm audit-item-field" maxlength="1000"
                                                placeholder="Optional" disabled>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary" id="submitPhysicalStockAudit">
                            <i class="fa-solid fa-clipboard-check me-1"></i>
                            Record Physical Count
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm" id="audit-history">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <h2 class="h5 mb-0">Physical Stock Audit History</h2>
            <span class="text-muted small">
                <?= e(number_format($totalAudits)) ?> audit record(s)
            </span>
        </div>
    </div>

    <div class="card-body">
        <form method="GET" action="<?= e($baseUrl) ?>#audit-history">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="audit-search" class="form-label">Search</label>
                    <input type="search" id="audit-search" name="search" class="form-control" value="<?= e($search) ?>"
                        placeholder="Reference, department, or creator">
                </div>

                <div class="col-6 col-md-2">
                    <label for="history-audit-type" class="form-label">Type</label>
                    <select id="history-audit-type" name="audit_type" class="form-select">
                        <option value="">All types</option>
                        <option value="consumable" <?= $historyAuditType === 'consumable' ? 'selected' : '' ?>>
                            Consumable
                        </option>
                        <option value="returnable" <?= $historyAuditType === 'returnable' ? 'selected' : '' ?>>
                            Returnable
                        </option>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="audit-status" class="form-label">Status</label>
                    <select id="audit-status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="recorded" <?= $status === 'recorded' ? 'selected' : '' ?>>Recorded</option>
                        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="audit-per-page" class="form-label">Rows</label>
                    <select id="audit-per-page" name="per_page" class="form-select">
                        <?php foreach ([10, 25, 50, 100] as $size): ?>
                            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>>
                                <?= $size ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fa-solid fa-filter"></i>
                        Filter
                    </button>

                    <a href="<?= e($baseUrl . '#audit-history') ?>" class="btn btn-outline-secondary"
                        title="Clear filters" aria-label="Clear filters">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </div>
        </form>

        <hr>

        <?php if (empty($audits)): ?>
            <div class="text-center py-5">
                <i class="fa-solid fa-clipboard-check fs-1 text-muted mb-3"></i>
                <h3 class="h5">No Physical Stock Audits Found</h3>
                <p class="text-muted mb-0">
                    No audit records match your current filters.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Department</th>
                            <th>Type</th>
                            <th>Audit Date</th>
                            <th>Recorded By</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audits as $audit): ?>
                            <?php
                            $statusClass = match ($audit['audit_status']) {
                                'recorded', 'approved' => 'bg-success',
                                'pending' => 'bg-warning text-dark',
                                'rejected' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                            ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= e((string) $audit['audit_reference']) ?>
                                </td>
                                <td><?= e((string) $audit['department_name']) ?></td>
                                <td>
                                    <?= $audit['audit_type'] === 'consumable'
                                        ? 'Consumable'
                                        : 'Returnable' ?>
                                </td>
                                <td><?= e((string) $audit['audit_date']) ?></td>
                                <td>
                                    <?= e(trim(
                                        (string) $audit['creator_first_name']
                                        . ' '
                                        . (string) $audit['creator_last_name']
                                    )) ?>
                                </td>
                                <td>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e(ucfirst((string) $audit['audit_status'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary view-audit-button"
                                        data-audit-id="<?= (int) $audit['id'] ?>">
                                        <i class="fa-solid fa-eye me-1"></i>
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                    <div class="text-muted small">
                        Showing
                        <?= e((string) (($page - 1) * $perPage + 1)) ?>–<?= e((string) min($page * $perPage, $totalAudits)) ?>
                        of <?= e((string) $totalAudits) ?> records
                    </div>

                    <nav aria-label="Physical stock audit pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e($paginationUrl(max(1, $page - 1))) ?>">Previous</a>
                            </li>

                            <li class="page-item disabled">
                                <span class="page-link">
                                    Page <?= $page ?> of <?= $totalPages ?>
                                </span>
                            </li>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="physicalStockAuditDetailsModal" tabindex="-1"
    aria-labelledby="physicalStockAuditDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="physicalStockAuditDetailsModalLabel">
                        Physical Stock Audit Details
                    </h5>
                    <div class="small text-muted" id="physicalStockAuditDetailsReference"></div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="physicalStockAuditDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2 mb-0">
                        Loading audit details...
                    </p>
                </div>

                <div id="physicalStockAuditDetailsError" class="alert alert-danger d-none" role="alert"></div>

                <div id="physicalStockAuditDetailsContent" class="d-none">
                    <div class="row g-3 mb-4" id="physicalStockAuditDetailsSummary"></div>

                    <h6 class="mb-3">Audited Items</h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead id="physicalStockAuditDetailsHead"></thead>
                            <tbody id="physicalStockAuditDetailsBody"></tbody>
                        </table>
                    </div>

                    <div id="physicalStockAuditReturnHistorySection" class="d-none mt-4">
                        <h6 class="mb-3">Return History</h6>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Returned Quantity</th>
                                        <th>Returned At</th>
                                        <th>Received By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="physicalStockAuditReturnHistoryBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(base_url('/public/assets/js/physical_stock_audits.js')) ?>"></script>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>