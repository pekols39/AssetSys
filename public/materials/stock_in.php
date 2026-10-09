<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
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

if ($authorization->departmentId() === null) {
    http_response_code(403);
    exit('Your account must be assigned to a department.');
}

$materialService = new MaterialService();
$materials = $materialService->getActive();

$db = Database::connection();

$stmt = $db->query(
    'SELECT id, name
     FROM vendors
     ORDER BY name ASC'
);

$vendors = $stmt->fetchAll();

$pageTitle = 'Receive Materials';

require_once dirname(__DIR__) . '/includes/app_header.php';

?>

<div class="d-flex flex-column flex-md-row
            justify-content-between align-items-md-center
            gap-3 mb-4">

    <div>
        <div class="text-muted small mb-1">
            Materials &amp; Stock
        </div>

        <h1 class="h3 mb-1">Receive Materials</h1>

        <p class="text-muted mb-0">
            Record newly received supplies into your department inventory.
        </p>
    </div>

    <a href="<?= e(base_url('/public/materials/stock.php')) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>
        Back to Stock
    </a>

</div>


<div class="card border-0 shadow-sm">

    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">Stock-In Information</h2>
    </div>

    <div class="card-body">

        <?php if (empty($materials)): ?>

            <div class="alert alert-warning mb-0">
                No active materials are available.
                Create a material before recording stock.
            </div>

        <?php else: ?>

            <form id="stockInForm">

                <?= Csrf::field() ?>

                <div class="row g-3">

                    <div class="col-12 col-md-6">

                        <label for="material_id" class="form-label">
                            Material <span class="text-danger">*</span>
                        </label>

                        <select class="form-select" id="material_id" name="material_id" required>

                            <option value="">Select a material</option>

                            <?php foreach ($materials as $material): ?>

                                <option value="<?= (int) $material['id'] ?>">
                                    <?= e((string) $material['code']) ?>
                                    —
                                    <?= e((string) $material['name']) ?>
                                    (<?= e((string) $material['unit']) ?>)
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-12 col-md-6">

                        <label for="quantity" class="form-label">
                            Quantity Received
                            <span class="text-danger">*</span>
                        </label>

                        <input type="number" class="form-control" id="quantity" name="quantity" min="0.001" step="0.001"
                            required placeholder="Enter quantity received">

                        <div class="form-text">
                            Enter a quantity greater than zero.
                            Up to three decimal places are supported.
                        </div>

                    </div>


                    <div class="col-12 col-md-6">

                        <label for="vendor_id" class="form-label">
                            Vendor
                        </label>

                        <select class="form-select" id="vendor_id" name="vendor_id">

                            <option value="">
                                Select vendor (optional)
                            </option>

                            <?php foreach ($vendors as $vendor): ?>

                                <option value="<?= (int) $vendor['id'] ?>">
                                    <?= e((string) $vendor['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-12 col-md-6">

                        <label for="reference_number" class="form-label">
                            Reference Number
                        </label>

                        <input type="text" class="form-control" id="reference_number" name="reference_number"
                            maxlength="100" placeholder="e.g. PO or delivery receipt number">

                    </div>


                    <div class="col-12">

                        <label for="notes" class="form-label">
                            Notes
                        </label>

                        <textarea class="form-control" id="notes" name="notes" rows="3"
                            placeholder="Optional receiving notes"></textarea>

                    </div>

                </div>


                <hr class="my-4">

                <div class="d-flex flex-column flex-sm-row
                            justify-content-end gap-2">

                    <a href="<?= e(base_url('/public/materials/stock.php')) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>

                    <button type="submit" id="stockInSubmit" class="btn btn-primary">
                        <i class="fa-solid fa-arrow-down-to-line me-1"></i>
                        Receive Stock
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('stockInForm');

        if (!form) {
            return;
        }

        const submitButton = document.getElementById('stockInSubmit');

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            const confirmed = await Swal.fire({
                icon: 'question',
                title: 'Receive Stock?',
                text: 'This will increase your department stock balance and record a stock movement.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Receive Stock',
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
                        base_url('/public/api/stock_in.php'),
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
                        data.message || 'Unable to receive stock.'
                    );
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Stock Received',
                    text: data.message,
                    confirmButtonColor: '#007472'
                });

                window.location.href =
                    <?= json_encode(
                        base_url('/public/materials/stock.php'),
                        JSON_HEX_TAG
                        | JSON_HEX_APOS
                        | JSON_HEX_QUOT
                        | JSON_HEX_AMP
                    ) ?>;

            } catch (error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Receive Stock',
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


<?php

require_once dirname(__DIR__) . '/includes/app_footer.php';

?>