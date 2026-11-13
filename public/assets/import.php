<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to import inventory.');
}

$pageTitle = 'Import Asset Inventory';
require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Management</div>
        <h1 class="h3 mb-1">Import Asset Inventory</h1>
        <p class="text-muted mb-0">Upload the Asset .xlsx workbook for review and staging.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(base_url('/public/assets/import_history.php')) ?>" class="btn btn-outline-primary">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> Import History
        </a>
        <a href="<?= e(base_url('/public/assets/index.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Assets
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="alert alert-info">
            <strong>Staging only:</strong> this upload stores the source asset records for review.
            It does not create assets, change departments or locations, or alter existing records.
        </div>

        <form method="post"
              action="<?= e(base_url('/public/assets/import_process.php')) ?>"
              enctype="multipart/form-data"
              data-swal-confirm="true"
              data-swal-title="Upload asset workbook?"
              data-swal-text="The workbook will be uploaded for review. No assets will be created yet."
              data-swal-confirm-text="Yes, Upload">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label for="finance_workbook" class="form-label">Asset inventory workbook (.xlsx)</label>
                <input
                    type="file"
                    class="form-control"
                    id="finance_workbook"
                    name="finance_workbook"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    required
                >
                <div class="form-text">Maximum file size: 20 MB. The workbook must contain the 12 required inventory columns. The QR Code Generator column is not imported; AssetSys manages QR identities separately.</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-upload me-1"></i> Upload and Preview
            </button>
        </form>
    </div>
</div>
