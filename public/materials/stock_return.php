<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\StockMovement;
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

if ($authorization->departmentId() === null) {
    http_response_code(403);
    exit('Your account must be assigned to a department.');
}

$materialService = new MaterialService();

$materials = array_values(array_filter(
    $materialService->getActive(),
    static fn(array $material): bool =>
        (int) ($material['is_returnable'] ?? 0) === 1
));

$departmentId = $authorization->departmentId();

$returnableStockOuts = (new StockMovement())
    ->getReturnableStockOutsByDepartment($departmentId);


$pageTitle = 'Return Materials';

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Return Materials</h1>
            <p class="text-muted mb-0">
                Record materials returned to your department's inventory.
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
                <i class="fas fa-undo me-2" style="color: #007472;"></i>
                Return Details
            </h2>
        </div>

        <div class="card-body">

            <?php if (empty($materials)): ?>
                <div class="alert alert-info mb-0">
                    No active returnable materials are available.
                    Check that the material is active and marked as returnable.
                </div>
            <?php else: ?>
                <form id="stockReturnForm">
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
                            <label for="original_movement_id" class="form-label">
                                Original Stock Out
                                <span class="text-danger">*</span>
                            </label>

                            <select id="original_movement_id" name="original_movement_id" class="form-select" required
                                disabled>
                                <option value="">Select a material first</option>

                                <?php foreach ($returnableStockOuts as $movement): ?>
                                    <option value="<?= (int) $movement['movement_id'] ?>"
                                        data-material-id="<?= (int) $movement['material_id'] ?>" data-remaining="<?= htmlspecialchars(
                                               (string) $movement['remaining_quantity'],
                                               ENT_QUOTES,
                                               'UTF-8'
                                           ) ?>">
                                        <?= htmlspecialchars(
                                            (string) $movement['material_code']
                                            . ' — '
                                            . $movement['material_name']
                                            . ' | To: '
                                            . ($movement['issued_to_name'] ?: 'Unknown recipient')
                                            . ' | Remaining: '
                                            . number_format((float) $movement['remaining_quantity'], 3, '.', '')
                                            . ' '
                                            . ($movement['material_unit'] ?? ''),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                        | <?= htmlspecialchars(
                                            (string) $movement['moved_at'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="form-text" id="returnableQuantityHelp">
                                Select a material to see Stock Out transactions with quantities still available to return.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="quantity" class="form-label">
                                Return Quantity
                                <span class="text-danger">*</span>
                            </label>

                            <input type="number" id="quantity" name="quantity" class="form-control" min="0.001" step="0.001"
                                inputmode="decimal" required>
                        </div>

                        <div class="col-12">
                            <label for="reference_number" class="form-label">
                                Reference Number
                            </label>

                            <input type="text" id="reference_number" name="reference_number" class="form-control"
                                maxlength="100" placeholder="Optional return or issue reference">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label">
                                Notes
                            </label>

                            <textarea id="notes" name="notes" class="form-control" rows="3" maxlength="1000"
                                placeholder="Optional return condition or additional details"></textarea>
                        </div>

                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" id="stockReturnSubmit" class="btn btn-primary"
                            style="background-color: #007472; border-color: #007472;">
                            <i class="fas fa-undo me-1"></i>
                            Record Return
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
        const form = document.getElementById('stockReturnForm');

        if (!form) {
            return;
        }

        const submitButton = document.getElementById('stockReturnSubmit');
        const materialSelect = document.getElementById('material_id');
        const originalMovementSelect = document.getElementById('original_movement_id');
        const quantityInput = document.getElementById('quantity');
        const returnableQuantityHelp = document.getElementById('returnableQuantityHelp');

        const originalMovementOptions = Array.from(
            originalMovementSelect.querySelectorAll('option')
        ).filter(option => option.value !== '');

        function updateReturnableTransactions() {
            const selectedMaterialId = materialSelect.value;

            originalMovementSelect.value = '';
            quantityInput.value = '';
            quantityInput.removeAttribute('max');

            originalMovementSelect.replaceChildren();

            const placeholder = document.createElement('option');
            placeholder.value = '';

            const matchingOptions = originalMovementOptions.filter(
                option => option.dataset.materialId === selectedMaterialId
            );

            if (!selectedMaterialId) {
                placeholder.textContent = 'Select a material first';
                originalMovementSelect.add(placeholder);
                originalMovementSelect.disabled = true;
                quantityInput.disabled = true;
                returnableQuantityHelp.textContent =
                    'Select a material to see eligible Stock Out transactions.';
                return;
            }

            if (matchingOptions.length === 0) {
                placeholder.textContent = 'No outstanding Stock Out transactions';
                originalMovementSelect.add(placeholder);
                originalMovementSelect.disabled = true;
                quantityInput.disabled = true;
                returnableQuantityHelp.textContent =
                    'There are no quantities left to return for this material.';
                return;
            }

            placeholder.textContent = 'Select original Stock Out';
            originalMovementSelect.add(placeholder);

            matchingOptions.forEach(option => {
                originalMovementSelect.add(option.cloneNode(true));
            });

            originalMovementSelect.disabled = false;
            quantityInput.disabled = true;
            returnableQuantityHelp.textContent =
                'Select the original Stock Out transaction before entering the return quantity.';
        }

        function updateReturnQuantityLimit() {
            const selectedOption = originalMovementSelect.selectedOptions[0];

            quantityInput.value = '';

            if (!selectedOption || !selectedOption.value) {
                quantityInput.disabled = true;
                quantityInput.removeAttribute('max');
                returnableQuantityHelp.textContent =
                    'Select the original Stock Out transaction first.';
                return;
            }

            const remaining = Number(selectedOption.dataset.remaining);

            if (!Number.isFinite(remaining) || remaining <= 0) {
                quantityInput.disabled = true;
                quantityInput.removeAttribute('max');
                returnableQuantityHelp.textContent =
                    'This transaction has no remaining quantity to return.';
                return;
            }

            quantityInput.max = remaining.toFixed(3);
            quantityInput.disabled = false;

            returnableQuantityHelp.textContent =
                'Maximum return quantity: ' + remaining.toFixed(3)
                + '. You cannot return more than this amount.';
        }

        materialSelect.addEventListener('change', updateReturnableTransactions);
        originalMovementSelect.addEventListener('change', updateReturnQuantityLimit);

        updateReturnableTransactions();


        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            const selectedIssue =
                originalMovementSelect.selectedOptions[0];

            const remainingQuantity = Number(
                selectedIssue?.dataset.remaining
            );

            const requestedQuantity = Number(quantityInput.value);

            if (
                !selectedIssue?.value
                || !Number.isFinite(remainingQuantity)
                || !Number.isFinite(requestedQuantity)
                || requestedQuantity <= 0
                || requestedQuantity > remainingQuantity
            ) {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Return Quantity',
                    text: 'Select an original Stock Out transaction and enter a quantity no greater than the remaining amount.',
                    confirmButtonColor: '#007472'
                });

                return;
            }

            const confirmed = await Swal.fire({
                icon: 'question',
                title: 'Record Material Return?',
                text: 'The returned quantity will be added to your department stock.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Record Return',
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
                        base_url('/public/api/stock_return.php'),
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
                        data.message || 'Unable to record material return.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Return Recorded',
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
                    title: 'Unable to Record Return',
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