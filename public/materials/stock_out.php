<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialService;

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
    exit('403 Forbidden: Inventory access required.');
}

$departmentId = $authorization->departmentId();

if ($departmentId === null) {
    http_response_code(403);
    exit('Your account must be assigned to a department.');
}

$materialService = new MaterialService();
$materials = $materialService->getActive();

$pageTitle = 'Issue Materials';

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Issue Materials</h1>
            <p class="text-muted mb-0">
                Issue materials from your department's available stock.
            </p>
        </div>

        <a href="<?= htmlspecialchars(
            base_url('/public/materials/stock.php'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>
            Back to Stock
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                <i class="fas fa-box-open me-2" style="color: #007472;"></i>
                Issue Details
            </h2>
        </div>

        <div class="card-body">
            <?php if (empty($materials)): ?>
                <div class="alert alert-warning mb-0">
                    No active materials are available to issue.
                    Add or activate a material before proceeding.
                </div>
            <?php else: ?>
                <form id="stockOutForm" novalidate>
                    <?= Csrf::field() ?>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label for="material_id" class="form-label">
                                Material <span class="text-danger">*</span>
                            </label>

                            <select id="material_id" name="material_id" class="form-select" required>
                                <option value="">Select material</option>

                                <?php foreach ($materials as $material): ?>
                                    <option value="<?= (int) $material['id'] ?>">
                                        <?= htmlspecialchars(
                                            (string) $material['code'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                        —
                                        <?= htmlspecialchars(
                                            (string) $material['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="quantity" class="form-label">
                                Quantity <span class="text-danger">*</span>
                            </label>

                            <input type="number" id="quantity" name="quantity" class="form-control" min="0.001" step="0.001"
                                inputmode="decimal" required>

                            <div class="form-text">
                                The quantity must be greater than zero and
                                cannot exceed available stock.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="issued_to_name" class="form-label">
                                Recipient Name <span class="text-danger">*</span>
                            </label>

                            <input type="text" id="issued_to_name" name="issued_to_name" class="form-control"
                                maxlength="255" autocomplete="name" required
                                placeholder="Enter the employee or recipient's full name">

                            <div class="form-text">
                                Enter the full name of the person receiving the materials.
                                They do not need an AssetSys account.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="reference_number" class="form-label">
                                Reference Number
                            </label>

                            <input type="text" id="reference_number" name="reference_number" class="form-control"
                                maxlength="100" placeholder="e.g. ISS-2026-0001">
                        </div>

                        <div class="col-12">
                            <label for="purpose" class="form-label">
                                Purpose <span class="text-danger">*</span>
                            </label>

                            <input type="text" id="purpose" name="purpose" class="form-control" maxlength="255" required
                                placeholder="Explain why these materials are being issued">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label">
                                Notes
                            </label>

                            <textarea id="notes" name="notes" class="form-control" rows="3" maxlength="1000"
                                placeholder="Optional additional details"></textarea>
                        </div>

                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" id="stockOutSubmit" class="btn btn-primary"
                            style="background-color: #007472; border-color: #007472;">
                            <i class="fas fa-box-open me-1"></i>
                            Issue Stock
                        </button>

                        <a href="<?= htmlspecialchars(
                            base_url('/public/materials/stock.php'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('stockOutForm');

        if (!form) {
            return;
        }

        const submitButton = document.getElementById('stockOutSubmit');

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            const confirmed = await Swal.fire({
                icon: 'question',
                title: 'Issue Materials?',
                text: 'This will deduct the specified quantity from your department stock.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Issue Stock',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#007472'
            });

            if (!confirmed.isConfirmed) {
                return;
            }

            submitButton.disabled = true;

            const originalButtonText = submitButton.innerHTML;

            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            try {
                const response = await fetch(
                    <?= json_encode(
                        base_url('/public/api/stock_out.php'),
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
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
                        data.message || 'Unable to issue stock.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Materials Issued',
                    text: data.message,
                    confirmButtonColor: '#007472'
                });

                window.location.href = <?= json_encode(
                    base_url('/public/materials/stock.php'),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                ) ?>;
            } catch (error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Issue Stock',
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

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>