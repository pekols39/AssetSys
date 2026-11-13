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
    exit('You do not have permission to view Finance import history.');
}

$batches = (new AssetImportService())->getRecentBatches(200);
$pageTitle = 'Finance Import History';
require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Management</div>
        <h1 class="h3 mb-1">Finance Import History</h1>
        <p class="text-muted mb-0">Access previous workbooks, review imported records, and resume batches that need attention.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(base_url('/public/assets/import.php')) ?>" class="btn btn-primary">
            <i class="fa-solid fa-file-import me-1"></i> Upload Finance Excel
        </a>
        <a href="<?= e(base_url('/public/assets/index.php')) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Assets
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">Previous import batches</h2>
        <div class="text-muted small">The latest 200 batches are shown, newest first.</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Workbook</th>
                    <th>Uploaded</th>
                    <th class="text-end">Source Rows</th>
                    <th class="text-end">Imported</th>
                    <th class="text-end">Failed</th>
                    <th>Status</th>
                    <th>Uploaded By</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <?php
                    $status = strtolower((string) $batch['batch_status']);
                    $badgeClass = match ($status) {
                        'completed' => 'bg-success',
                        'preview' => 'bg-warning text-dark',
                        'processing' => 'bg-info text-dark',
                        'failed' => 'bg-danger',
                        default => 'bg-secondary',
                    };
                    $uploader = trim((string) ($batch['first_name'] ?? '') . ' ' . (string) ($batch['last_name'] ?? ''));
                ?>
                <tr>
                    <td class="fw-semibold">#<?= (int) $batch['id'] ?></td>
                    <td><?= e((string) $batch['original_filename']) ?></td>
                    <td class="text-nowrap"><?= e((string) $batch['created_at']) ?></td>
                    <td class="text-end"><?= number_format((int) $batch['total_rows']) ?></td>
                    <td class="text-end text-success"><?= number_format((int) $batch['imported_rows']) ?></td>
                    <td class="text-end <?= (int) $batch['failed_rows'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= number_format((int) $batch['failed_rows']) ?></td>
                    <td><span class="badge <?= e($badgeClass) ?> text-capitalize"><?= e($status) ?></span></td>
                    <td><?= e($uploader !== '' ? $uploader : 'Unknown') ?></td>
                    <td class="text-end">
                        <a href="<?= e(base_url('/public/assets/import_batch.php?id=' . (int) $batch['id'])) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-folder-open me-1"></i> Open
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($batches === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No Finance import batches found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>
