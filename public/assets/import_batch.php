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
$mappingOptions = $importService->getMappingOptions((int) $batchId);
$pageTitle = 'Asset Import Batch #' . (int) $batch['id'];
require_once dirname(__DIR__) . '/includes/app_header.php';

$previewHeaders = [
    'LOCATION (Main)',
    'OWNER / DEPARTMENT',
    'CATEGORY',
    'Item ID',
    'Item name',
    'FFE NUMBER',
    'CODE',
    'Status',
    'SUPPLIER',
    'PO NUMBER',
    'Price',
    'DATE PURCHASE',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Inventory Import</div>
        <h1 class="h3 mb-1">Import Batch #<?= (int) $batch['id'] ?></h1>
        <p class="text-muted mb-0"><?= e($batch['original_filename']) ?></p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(base_url('/public/assets/import_history.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> Import History
        </a>
        <a href="<?= e(base_url('/public/assets/import.php')) ?>" class="btn btn-outline-primary">
            <i class="fa-solid fa-upload me-1"></i> Upload Another Workbook
        </a>
    </div>
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
    <div class="alert alert-info">
        <strong>Automatic reference handling:</strong> existing categories and statuses will be reused;
        missing values from the workbook will be added to Asset Dropdown Management during import.
        Blank Category cells will use <strong>UNCATEGORIZED</strong>; blank Status cells will use <strong>AVAILABLE</strong>.
        Finance Item ID and the original Finance Status are also retained in their dedicated fields.
    </div>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">Detected Finance values</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="fw-semibold mb-2">Categories (<?= count($mappingOptions['categories']) ?> distinct)</div>
                    <div class="small text-muted"><?= e(implode(', ', array_filter($mappingOptions['categories'], static fn ($value) => $value !== '__BLANK__'))) ?></div>
                    <?php if (($mappingOptions['blank_category_rows'] ?? 0) > 0): ?>
                        <div class="small text-warning mt-1"><?= number_format((int) $mappingOptions['blank_category_rows']) ?> blank row(s) → UNCATEGORIZED</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="fw-semibold mb-2">Statuses (<?= count($mappingOptions['statuses']) ?> distinct)</div>
                    <div class="small text-muted"><?= e(implode(', ', array_filter($mappingOptions['statuses'], static fn ($value) => $value !== '__BLANK__'))) ?></div>
                    <?php if (($mappingOptions['blank_status_rows'] ?? 0) > 0): ?>
                        <div class="small text-warning mt-1"><?= number_format((int) $mappingOptions['blank_status_rows']) ?> blank row(s) → AVAILABLE</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <form method="post" action="<?= e(base_url('/public/assets/import_execute.php')) ?>"
          data-swal-confirm="true" data-swal-title="Import staged assets?" data-swal-text="The staged records will be created in AssetSys. Missing categories and statuses will be added automatically. Existing assets will not be updated." data-swal-confirm-text="Yes, Import"
          class="mb-4">
        <form method="post" action="<?= e(base_url('/public/assets/import_execute.php')) ?>"
              data-swal-confirm="true" data-swal-title="Import staged assets?" data-swal-text="The staged records will be created in AssetSys. Existing assets will not be updated." data-swal-confirm-text="Yes, Import"
              class="mb-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-database me-1"></i> Import Staged Records into AssetSys
            </button>
        </form>
<?php elseif ($batch['batch_status'] === 'completed'): ?>
    <div class="alert alert-success">
        Import processing completed. <?= number_format((int) $batch['imported_rows']) ?> asset(s) created;
        <?= number_format((int) $batch['failed_rows']) ?> row(s) require review.
    </div>
    <?php if ($failedCount > 0): ?>
        <form method="post" action="<?= e(base_url('/public/assets/import_execute.php')) ?>"
              data-swal-confirm="true" data-swal-title="Retry failed rows?" data-swal-text="Only failed rows will be retried. The <?= number_format((int) $batch['imported_rows']) ?> assets already created will not be imported again." data-swal-confirm-text="Yes, Retry"
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
               href="<?= e(base_url('/public/assets/import_failed_export.php?id=' . (int) $batchId)) ?>" data-swal-confirm="true" data-swal-title="Export failed rows?" data-swal-text="A CSV file containing the failed source rows will be downloaded." data-swal-confirm-text="Export CSV">
                <i class="fa-solid fa-file-csv me-1"></i> Export Failed Rows (CSV)
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Excel Row</th><th>Item Name</th><th>Finance Category</th><th>Finance Item ID</th><th>Finance Status</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($failedRows as $failed): ?>
                    <tr>
                        <td><?= (int) $failed['source_row_number'] ?></td>
                        <td><?= e((string) ($failed['source_values']['Item name'] ?? '')) ?></td>
                        <td><?= e((string) ($failed['source_values']['CATEGORY'] ?? '')) ?></td>
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
        <div class="text-muted small">Original source values are shown without category or status conversion.</div>
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
