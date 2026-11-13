<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;
use App\Services\ReferenceDataService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to map import data.');
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
if ($batch['batch_status'] !== 'preview') {
    http_response_code(409);
    exit('This batch is no longer available for mapping.');
}

$options = $importService->getMappingOptions((int) $batchId);
$saved = $importService->getSavedMapping((int) $batchId);
$referenceData = new ReferenceDataService();
$categories = $referenceData->getAssetCategories();
$statuses = $referenceData->getAssetStatuses();

$pageTitle = 'Map Asset Inventory';
require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Inventory Import</div>
        <h1 class="h3 mb-1">Review Reference Mappings</h1>
        <p class="text-muted mb-0">Batch #<?= (int) $batch['id'] ?> — <?= e($batch['original_filename']) ?></p>
    </div>
    <a href="<?= e(base_url('/public/assets/import_batch.php?id=' . (int) $batchId)) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Preview
    </a>
</div>

<div class="alert alert-warning">
    Map each source Item ID to an AssetSys category and each source Status to an AssetSys status.
    These are manual reference choices only. Original source values remain unchanged in staging.
    No assets are created on this page.
</div>

<form method="post" action="<?= e(base_url('/public/assets/import_mapping_process.php')) ?>" data-swal-confirm="true" data-swal-title="Save asset mappings?" data-swal-text="The selected category and status mappings will be saved for this batch." data-swal-confirm-text="Yes, Save">
    <?= Csrf::field() ?>
    <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Asset Item ID → AssetSys Category</h2></div>
        <div class="card-body">
            <?php foreach ($options['item_ids'] as $value): ?>
                <div class="row align-items-center g-2 mb-3">
                    <div class="col-md-5"><label class="form-label mb-md-0"><?= $value === '__BLANK__' ? '(Blank cell)' : e($value) ?></label></div>
                    <div class="col-md-7">
                        <select class="form-select" name="mapping[item_ids][<?= e($value) ?>]" required>
                            <option value="">Select AssetSys category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"
                                    <?= (int) ($saved['item_ids'][$value] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($options['item_ids'] === []): ?><p class="text-muted mb-0">No non-empty Item ID values found.</p><?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Asset Status → AssetSys Status</h2></div>
        <div class="card-body">
            <?php foreach ($options['statuses'] as $value): ?>
                <div class="row align-items-center g-2 mb-3">
                    <div class="col-md-5"><label class="form-label mb-md-0"><?= $value === '__BLANK__' ? '(Blank cell)' : e($value) ?></label></div>
                    <div class="col-md-7">
                        <select class="form-select" name="mapping[statuses][<?= e($value) ?>]" required>
                            <option value="">Select AssetSys status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= (int) $status['id'] ?>"
                                    <?= (int) ($saved['statuses'][$value] ?? 0) === (int) $status['id'] ? 'selected' : '' ?>>
                                    <?= e($status['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($options['statuses'] === []): ?><p class="text-muted mb-0">No non-empty Status values found.</p><?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i> Save Mappings
    </button>
</form>
