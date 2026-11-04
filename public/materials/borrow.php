<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\MaterialBorrowingService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();
$authorization = new AuthorizationService();
if ($authorization->currentUser() === null) {
    http_response_code(401);
    exit('Authentication required.');
}
if (!$authorization->isPropertyCustodian()) {
    http_response_code(403);
    exit('You do not have permission to access material borrowing.');
}

$service = new MaterialBorrowingService();
$materials = $service->getBorrowableMaterials();
$pageTitle = 'Borrow Materials';
$pageStylesheet = 'css/material-borrowings.css';
require_once dirname(__DIR__) . '/includes/app_header.php';

$now = new DateTimeImmutable();
$defaultBorrowedAt = $now->format('Y-m-d\TH:i');
$defaultReturnDate = $now->modify('+7 days')->format('Y-m-d');
?>

<div class="container-fluid pt-1 pb-4 material-borrowing-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="text-muted small mb-1">Materials / Returnable Items</div>
            <h1 class="h3 mb-1">Borrow Materials</h1>
            <p class="text-muted mb-0">Record the temporary release of returnable materials and track their expected
                return.</p>
        </div>
        <a href="<?= e(base_url('/public/materials/borrowings.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-clipboard-list me-1"></i> Borrowing Records
        </a>
    </div>

    <div class="alert alert-info border-0">
        <i class="fa-solid fa-circle-info me-2"></i>
        Only active materials marked as returnable are listed. Recording a borrow immediately deducts its quantity from
        organization-wide available stock.
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0"><i class="fa-solid fa-hand-holding me-2 text-teal"></i>Borrowing Details</h2>
        </div>
        <div class="card-body">
            <?php if (!$materials): ?>
                <div class="alert alert-warning mb-0">No active returnable materials with an organization-wide stock record
                    are currently available.</div>
            <?php else: ?>
                <form id="materialBorrowForm" novalidate>
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label for="borrowMaterialId" class="form-label">Material <span
                                    class="text-danger">*</span></label>
                            <select id="borrowMaterialId" name="material_id" class="form-select" required>
                                <option value="">Select returnable material</option>
                                <?php foreach ($materials as $material): ?>
                                    <option value="<?= (int) $material['material_id'] ?>"
                                        data-available="<?= e((string) $material['available_quantity']) ?>"
                                        data-unit="<?= e((string) $material['unit']) ?>">
                                        <?= e((string) $material['code']) ?> — <?= e((string) $material['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="borrowAvailableStock">Select a material to view available stock.
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <label for="borrowQuantity" class="form-label">Quantity <span
                                    class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="borrowQuantity" name="quantity" min="0.001"
                                step="0.001" inputmode="decimal" required>
                        </div>
                        <div class="col-md-6">
                            <label for="borrowerName" class="form-label">Borrower Full Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="borrowerName" name="borrower_name" maxlength="255"
                                autocomplete="name" required>
                            <div class="form-text">The borrower does not need an AssetSys account.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="borrowLocation" class="form-label">Location / Where Used <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="borrowLocation" name="location" maxlength="255"
                                required placeholder="e.g. Conference Room, Building A">
                        </div>
                        <div class="col-md-6">
                            <label for="borrowedAt" class="form-label">Borrow Date &amp; Time <span
                                    class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="borrowedAt" name="borrowed_at"
                                value="<?= e($defaultBorrowedAt) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="expectedReturnDate" class="form-label">Expected Return Date <span
                                    class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="expectedReturnDate" name="expected_return_date"
                                value="<?= e($defaultReturnDate) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="borrowPurpose" class="form-label">Purpose <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="borrowPurpose" name="purpose" maxlength="255"
                                required placeholder="Reason for borrowing">
                        </div>
                        <div class="col-md-6">
                            <label for="borrowReference" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="borrowReference" name="reference_number"
                                maxlength="100" placeholder="Optional reference number">
                        </div>
                        <div class="col-12">
                            <label for="borrowNotes" class="form-label">Remarks</label>
                            <textarea class="form-control" id="borrowNotes" name="notes" rows="3" maxlength="1000"
                                placeholder="Optional remarks"></textarea>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" id="submitMaterialBorrow" class="btn btn-primary material-borrow-primary">
                            <i class="fa-solid fa-hand-holding me-1"></i> Record Borrowing
                        </button>
                        <a href="<?= e(base_url('/public/materials/borrowings.php')) ?>"
                            class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/material-borrowings.js')) ?>" defer></script>
<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>