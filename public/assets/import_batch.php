<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;
use App\Helpers\Csrf;

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
$failedRows = $importService->getFailedRows((int) $batchId, 100);
$failedCount = $importService->countFailedRows((int) $batchId);
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

<?php if ($batch['batch_status'] === 'preview'): ?>
    <div class="alert alert-success">
        The workbook has been staged. No AssetSys assets or reference records have been created or changed.
    </div>
    <div class="alert <?= $mappingComplete ? 'alert-success' : 'alert-warning' ?>">
        <?= $mappingComplete
            ? 'Category and status mappings are saved. You can now import the staged records.'
            : 'Review and save the Finance Item ID and Status mappings before importing.' ?>
    </div>
    <?php if ($mappingComplete): ?>
        <form method="post" action="<?= e(base_url('/public/assets/import_execute.php')) ?>"
              onsubmit="return confirm('Import the staged rows into AssetSys now? Existing assets will not be updated.');"
              class="mb-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-database me-1"></i> Import Staged Records into AssetSys
            </button>
        </form>
    <?php endif; ?>
<?php elseif ($batch['batch_status'] === 'completed'): ?>
    <div class="alert alert-success">
        Import processing completed. <?= number_format((int) $batch['imported_rows']) ?> asset(s) created;
        <?= number_format((int) $batch['failed_rows']) ?> row(s) require review.
    </div>
    <?php if ($failedCount > 0 && $mappingComplete): ?>
        <form method="post" action="<?= e(base_url('/public/assets/import_execute.php')) ?>"
              onsubmit="return confirm('Retry only the failed rows? The <?= number_format((int) $batch['imported_rows']) ?> assets already created will not be imported again.');"
              class="mb-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">
            <button type="submit" class="btn btn-warning">
                <i class="fa-solid fa-rotate me-1"></i> Retry Failed Rows (<?= number_format($failedCount) ?>)
            </button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($failedCount > 0): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <div>
                <h2 class="h5 mb-0 text-danger">Failed rows (<?= number_format($failedCount) ?>)</h2>
                <div class="text-muted small">These source rows were not created as assets. Review the reason before correcting or re-importing.</div>
            </div>
            <a class="btn btn-outline-danger btn-sm"
               href="<?= e(base_url('/public/assets/import_failed_export.php?id=' . (int) $batchId)) ?>">
                <i class="fa-solid fa-file-csv me-1"></i> Export Failed Rows (CSV)
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Excel Row</th><th>Item Name</th><th>Finance Item ID</th><th>Finance Status</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($failedRows as $failed): ?>
                    <tr>
                        <td><?= (int) $failed['source_row_number'] ?></td>
                        <td><?= e((string) ($failed['source_values']['Item name'] ?? '')) ?></td>
                        <td><?= e((string) ($failed['source_values']['Item ID'] ?? '')) ?></td>
                        <td><?= e((string) ($failed['source_values']['Status'] ?? '')) ?></td>
                        <td class="text-danger"><?= e((string) ($failed['validation_errors'] ?? 'Unknown error')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($failedCount > count($failedRows)): ?>
                <div class="p-3 text-muted small">Showing first <?= count($failedRows) ?> failed rows. Export the CSV to view all <?= number_format($failedCount) ?>.</div>
            <?php endif; ?>
        </div>
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
                <thead><tr><th>Excel Row</th><?php foreach ($previewHeaders as $header): ?><th><?= e($header) ?></th><?php endforeach; ?><th>Import Result</th><th>Asset ID</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['source_row_number'] ?></td>
                        <?php foreach ($previewHeaders as $header): ?>
                            <td><?= e((string) ($row['source_values'][$header] ?? '')) ?></td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($row['row_status'] === 'imported'): ?>
                                <span class="badge bg-success">Imported</span>
                            <?php elseif ($row['row_status'] === 'failed'): ?>
                                <span class="badge bg-danger">Failed</span>
                            <?php elseif ($row['row_status'] === 'skipped'): ?>
                                <span class="badge bg-secondary">Skipped</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['asset_id'] !== null ? (int) $row['asset_id'] : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><tr><td colspan="<?= count($previewHeaders) + 3 ?>" class="text-center py-4">No rows found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
