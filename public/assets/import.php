<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canManageAssets()) {
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
        <p class="text-muted mb-0">Upload the official Finance asset inventory workbook (.xlsx). Asset categories and statuses are matched or added automatically.</p>
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
            <strong>Safe preview first:</strong> uploading only stages the source rows. No assets or dropdown values are created until you confirm the import.
            During import, missing Finance Category and Status values are added automatically. Blank Category becomes UNCATEGORIZED; blank Status becomes AVAILABLE.
        </div>

        <form method="post"
              action="<?= e(base_url('/public/assets/import_process.php')) ?>"
              enctype="multipart/form-data"
              data-swal-confirm="true"
              data-swal-title="Upload asset workbook?"
              data-swal-text="The workbook will be staged for preview. No assets or dropdown values will be created until you confirm the import."
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
                <div class="form-text">Maximum file size: 20 MB. The main inventory sheet must include LOCATION (or LOCATION (Main)), OWNER / DEPARTMENT, CATEGORY, Item ID, Item name, FFE NUMBER, CODE, Status, SUPPLIER, PO NUMBER, Price, and DATE PURCHASE. If both LOCATION (Main) and LOCATION columns are present, the second LOCATION is retained as additional source data. The QR Code Generator column is not imported; AssetSys manages QR identities separately.</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-upload me-1"></i> Upload and Preview
            </button>
        </form>
    </div>
</div>
