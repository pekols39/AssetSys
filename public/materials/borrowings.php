<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialBorrowingService;

AuthMiddleware::handle();
$authorization = new AuthorizationService();
if ($authorization->currentUser() === null) {
    http_response_code(401);
    exit('Authentication required.');
}
if (!$authorization->isPropertyCustodian()) {
    http_response_code(403);
    exit('You do not have permission to access borrowing records.');
}

$view = (string) ($_GET['view'] ?? 'outstanding');
$view = in_array($view, ['outstanding', 'history'], true) ? $view : 'outstanding';
$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => (string) ($_GET['status'] ?? 'all'),
];
if (!in_array($filters['status'], ['all', 'overdue', 'not_overdue'], true)) {
    $filters['status'] = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 10);
$perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

$service = new MaterialBorrowingService();
$result = $view === 'history'
    ? $service->getHistoryPage($filters, $page, $perPage)
    : $service->getOutstandingPage($filters, $page, $perPage);
$borrowings = $result['items'];
$page = $result['page'];
$totalPages = $result['total_pages'];
$total = $result['total'];

$pageTitle = $view === 'history' ? 'Borrowing History' : 'Outstanding Borrowings';
$pageStylesheet = 'css/material-borrowings.css';
require_once dirname(__DIR__) . '/includes/app_header.php';

$baseUrl = base_url('/public/materials/borrowings.php');
$query = ['view' => $view, 'search' => $filters['search'], 'status' => $filters['status'], 'per_page' => $perPage];
$pageUrl = static fn(int $target): string => $baseUrl . '?' . http_build_query(array_merge($query, ['page' => $target]));
$defaultReturnedAt = (new DateTimeImmutable())->format('Y-m-d\TH:i');
?>

<div class="container-fluid pt-1 py-4 material-borrowing-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="text-muted small mb-1">Materials / Returnable Items</div>
            <h1 class="h3 mb-1"><?= $view === 'history' ? 'Borrowing History' : 'Outstanding Borrowings' ?></h1>
            <p class="text-muted mb-0">Track borrowed returnable materials, partial returns, and overdue items.</p>
        </div>
        <a href="<?= e(base_url('/public/materials/borrow.php')) ?>" class="btn btn-primary material-borrow-primary">
            <i class="fa-solid fa-plus me-1"></i> New Borrowing
        </a>
    </div>

    <ul class="nav nav-tabs material-borrowing-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $view === 'outstanding' ? 'active' : '' ?>"
                href="<?= e($baseUrl . '?' . http_build_query(['view' => 'outstanding'])) ?>">Outstanding</a></li>
        <li class="nav-item"><a class="nav-link <?= $view === 'history' ? 'active' : '' ?>"
                href="<?= e($baseUrl . '?' . http_build_query(['view' => 'history'])) ?>">History</a></li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body border-bottom">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <div class="col-md-6 col-lg-5">
                    <label for="borrowingSearch" class="form-label">Search</label>
                    <input type="search" class="form-control" id="borrowingSearch" name="search"
                        value="<?= e($filters['search']) ?>" placeholder="Borrower, material, code, or reference">
                </div>
                <?php if ($view === 'outstanding'): ?>
                    <div class="col-md-3">
                        <label for="borrowingStatus" class="form-label">Due Status</label>
                        <select class="form-select" id="borrowingStatus" name="status">
                            <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All outstanding</option>
                            <option value="overdue" <?= $filters['status'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                            <option value="not_overdue" <?= $filters['status'] === 'not_overdue' ? 'selected' : '' ?>>Not
                                overdue</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label for="borrowingPerPage" class="form-label">Rows</label>
                    <select class="form-select" id="borrowingPerPage" name="per_page">
                        <?php foreach ([10, 25, 50, 100] as $size): ?>
                            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary material-borrow-primary flex-grow-1"><i
                            class="fa-solid fa-filter me-1"></i>Filter</button>
                    <a href="<?= e($baseUrl . '?view=' . $view) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 material-borrowing-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Borrower</th>
                        <th class="text-end">Borrowed</th>
                        <th class="text-end">Returned</th>
                        <th class="text-end">Outstanding</th>
                        <th>Borrowed On</th>
                        <th>Expected Return</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$borrowings): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">No borrowing records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($borrowings as $borrowing): ?>
                            <?php
                            $outstanding = (float) $borrowing['outstanding_quantity'];
                            $status = (string) $borrowing['borrowing_status'];
                            $badgeClass = $status === 'overdue' ? 'text-bg-danger' : ($status === 'returned' ? 'text-bg-success' : 'text-bg-warning');
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e((string) $borrowing['material_name']) ?></div>
                                    <div class="small text-muted"><?= e((string) $borrowing['material_code']) ?></div>
                                </td>
                                <td>
                                    <div><?= e((string) $borrowing['borrower_name']) ?></div>
                                    <div class="small text-muted"><?= e((string) $borrowing['location']) ?></div>
                                </td>
                                <td class="text-end"><?= e(number_format((float) $borrowing['borrowed_quantity'], 3)) ?> <span
                                        class="small text-muted"><?= e((string) $borrowing['material_unit']) ?></span></td>
                                <td class="text-end"><?= e(number_format((float) $borrowing['returned_quantity'], 3)) ?></td>
                                <td class="text-end fw-semibold"><?= e(number_format($outstanding, 3)) ?></td>
                                <td><?= e(date('d M Y, h:i A', strtotime((string) $borrowing['borrowed_at']))) ?></td>
                                <td><?= e(date('d M Y', strtotime((string) $borrowing['expected_return_date']))) ?></td>
                                <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst($status)) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-borrowing-details"
                                        data-id="<?= (int) $borrowing['id'] ?>" title="View details"><i
                                            class="fa-regular fa-eye"></i></button>
                                    <?php if ($outstanding > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success js-record-return"
                                            data-id="<?= (int) $borrowing['id'] ?>"
                                            data-outstanding="<?= e((string) $borrowing['outstanding_quantity']) ?>"
                                            data-material="<?= e((string) $borrowing['material_name']) ?>"
                                            data-borrower="<?= e((string) $borrowing['borrower_name']) ?>" title="Record return"><i
                                                class="fa-solid fa-rotate-left"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <small class="text-muted">Showing <?= count($borrowings) ?> of <?= $total ?> record(s)</small>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Borrowing pages">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link"
                                href="<?= e($pageUrl(max(1, $page - 1))) ?>">Previous</a></li>
                        <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of
                                <?= $totalPages ?></span></li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link"
                                href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>">Next</a></li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="borrowingDetailsModal" tabindex="-1" aria-labelledby="borrowingDetailsTitle"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="borrowingDetailsTitle">Borrowing Details</h2><button type="button"
                    class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="borrowingDetailsLoading" class="text-muted">Loading details...</div>
                <div id="borrowingDetailsContent"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="recordBorrowingReturnModal" tabindex="-1" aria-labelledby="recordBorrowingReturnTitle"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="materialBorrowReturnForm" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="borrowing_id" id="returnBorrowingId">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="recordBorrowingReturnTitle">Record Material Return</h2><button
                        type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border small" id="returnBorrowingSummary"></div>
                    <div class="mb-3"><label for="returnQuantity" class="form-label">Quantity Returned <span
                                class="text-danger">*</span></label><input type="number" class="form-control"
                            id="returnQuantity" name="quantity" min="0.001" step="0.001" required>
                        <div class="form-text" id="returnQuantityHelp"></div>
                    </div>
                    <div class="mb-3"><label for="returnedAt" class="form-label">Return Date &amp; Time <span
                                class="text-danger">*</span></label><input type="datetime-local" class="form-control"
                            id="returnedAt" name="returned_at" value="<?= e($defaultReturnedAt) ?>" required></div>
                    <div class="mb-3"><label for="returnCondition" class="form-label">Item Condition <span
                                class="text-danger">*</span></label><select class="form-select" id="returnCondition"
                            name="item_condition" required>
                            <option value="good">Good</option>
                            <option value="damaged">Damaged</option>
                            <option value="incomplete">Incomplete</option>
                            <option value="other">Other</option>
                        </select></div>
                    <div class="mb-3" id="returnConditionNotesGroup" hidden><label for="returnConditionNotes"
                            class="form-label">Condition Details <span class="text-danger">*</span></label><textarea
                            class="form-control" id="returnConditionNotes" name="condition_notes" rows="2"
                            maxlength="5000"></textarea></div>
                    <div><label for="returnNotes" class="form-label">Remarks</label><textarea class="form-control"
                            id="returnNotes" name="notes" rows="2" maxlength="1000"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">Cancel</button><button type="submit" id="submitBorrowingReturn"
                        class="btn btn-primary material-borrow-primary">Record Return</button></div>
            </form>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/material-borrowings.js')) ?>" defer></script>
<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>