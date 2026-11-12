<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to view import batches.');
}

$batchId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($batchId === false || $batchId <= 0) {
    http_response_code(400);
    exit('Invalid import batch.');
}

$importService = new AssetImportService();
$batch = $importService->getBatch((int) $batchId);

if (!$batch) {
    http_response_code(404);
    exit('Import batch not found.');
}

$rows = $importService->getBatchRows((int) $batchId, 25);
$savedMapping = $importService->getSavedMapping((int) $batchId);
$mappingComplete = !empty($savedMapping['item_ids']) && !empty($savedMapping['statuses']);
$pageTitle = 'Finance Import Batch #' . (int) $batch['id'];
require_once dirname(__DIR__) . '/includes/app_header.php';

$previewHeaders = [
    'LOCATION (Main)',
    'OWNER / DEPARTMENT',
    'Item ID',
    'Item name',
    'FFE NUMBER',
    'CODE',
    'Status',
    'SUPPLIER',
    'PO NUMBER',
    'Price',
    'DATE PURCHASE',
    'LOCATION',
    'QR CODE GENERATOR',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Finance Inventory Import</div>
        <h1 class="h3 mb-1">Import Batch #<?= (int) $batch['id'] ?></h1>
        <p class="text-muted mb-0"><?= e($batch['original_filename']) ?></p>
    </div>
    <?php if ($batch['batch_status'] === 'preview'): ?>
        <a href="<?= e(base_url('/public/assets/import_mapping.php?id=' . (int) $batchId)) ?>" class="btn btn-primary">
            <i class="fa-solid fa-list-check me-1"></i>
            Review Category and Status Mapping
        </a>
    <?php endif; ?>
    <a href="<?= e(base_url('/public/assets/import.php')) ?>" class="btn btn-outline-primary">
        <i class="fa-solid fa-upload me-1"></i> Upload Another Workbook
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Source records staged</div>
            <div class="h3 mb-0"><?= number_format((int) $batch['total_rows']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Imported to AssetSys</div>
            <div class="h3 mb-0"><?= number_format((int) $batch['imported_rows']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Skipped</div>
            <div class="h3 mb-0"><?= number_format((int) $batch['skipped_rows']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="text-muted small">Status</div>
            <div class="h3 mb-0 text-capitalize"><?= e($batch['batch_status']) ?></div>
        </div></div>
    </div>
</div>

<div class="alert alert-success">
    The workbook has been staged successfully. No AssetSys assets or reference records were created or changed.
</div>

<?php if ($batch['batch_status'] === 'preview'): ?>
    <div class="alert <?= $mappingComplete ? 'alert-success' : 'alert-warning' ?>">
        <?= $mappingComplete
            ? 'Category and status mappings have been saved. Asset creation is not enabled yet.'
            : 'Next step: review and save the Finance Item ID and Status mappings before asset creation.' ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">First 25 source rows</h2>
        <div class="text-muted small">Original Finance values are shown without category or status conversion.</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead><tr><th>Excel Row</th><?php foreach ($previewHeaders as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['source_row_number'] ?></td>
                        <?php foreach ($previewHeaders as $header): ?>
                            <td><?= e((string) ($row['source_values'][$header] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><tr><td colspan="<?= count($previewHeaders) + 1 ?>" class="text-center py-4">No rows found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
