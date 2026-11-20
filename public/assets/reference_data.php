<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetReferenceDataService;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->isPropertyCustodian()) {
    http_response_code(403);
    exit('Only the Property Custodian may manage asset dropdown values.');
}

$service = new AssetReferenceDataService();
$pageUrl = base_url('/public/assets/reference_data.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
            throw new RuntimeException('Your session or security token expired. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        $type = (string) ($_POST['type'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $name = (string) ($_POST['name'] ?? '');

        switch ($action) {
            case 'create':
                $service->create($type, $name);
                $message = 'New dropdown value added.';
                break;

            case 'update':
                $service->update($type, $id, $name);
                $message = 'Dropdown value updated.';
                break;

            case 'toggle':
                $active = (string) ($_POST['active'] ?? '') === '1';
                $service->setActive($type, $id, $active);
                $message = $active ? 'Dropdown value activated.' : 'Dropdown value deactivated.';
                break;

            case 'delete':
                $message = $service->delete($type, $id);
                break;

            default:
                throw new RuntimeException('Invalid reference-data action.');
        }

        header('Location: ' . $pageUrl . '?success=' . rawurlencode($message));
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $pageUrl . '?error=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$recordsByType = [];
foreach (AssetReferenceDataService::types() as $type => $config) {
    $recordsByType[$type] = $service->getAll($type);
}

$successMessage = trim((string) ($_GET['success'] ?? ''));
$errorMessage = trim((string) ($_GET['error'] ?? ''));

$pageTitle = 'Asset Dropdown Management';
require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Asset Management / Property Custodian</div>
        <h1 class="h3 mb-1">Asset Dropdown Management</h1>
        <p class="text-muted mb-0">
            Maintain the choices used when registering, editing, filtering, and reporting company assets.
        </p>
    </div>
    <a href="<?= e(base_url('/public/assets/')) ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Assets
    </a>
</div>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= e($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= e($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="alert alert-info" role="note">
    <strong>Property Custodian control:</strong>
    You can add, rename, activate, deactivate, or delete dropdown choices.
    Values already used by assets are preserved; deleting one will deactivate it instead.
    Deactivated values remain attached to existing records but are hidden from new selections.
</div>

<?php foreach (AssetReferenceDataService::types() as $type => $config): ?>
    <?php $records = $recordsByType[$type]; ?>
    <section class="card border-0 shadow-sm mb-4" id="<?= e($type) ?>">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-0"><?= e($config['label']) ?></h2>
                <small class="text-muted"><?= count($records) ?> value(s)</small>
            </div>
            <span class="badge text-bg-light">Asset master data</span>
        </div>

        <div class="card-body border-bottom">
            <form method="post" action="<?= e($pageUrl) ?>" class="row g-2 align-items-end">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <div class="col-12 col-md-8">
                    <label class="form-label" for="new-<?= e($type) ?>">Add <?= e($config['singular']) ?></label>
                    <input class="form-control" id="new-<?= e($type) ?>" name="name" maxlength="150"
                        placeholder="Enter <?= e(strtolower($config['singular'])) ?> name" required>
                </div>
                <div class="col-12 col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-plus me-1"></i> Add Value
                    </button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width: 220px;">Name</th>
                        <th style="width: 120px;">Status</th>
                        <th style="min-width: 360px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No values have been added.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <form method="post" action="<?= e($pageUrl) ?>" class="d-flex gap-2">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="type" value="<?= e($type) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                    <input class="form-control form-control-sm" name="name" maxlength="150"
                                        value="<?= e($record['name']) ?>" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Save name">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php if ((int) $record['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <form method="post" action="<?= e($pageUrl) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="type" value="<?= e($type) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int) $record['is_active'] === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <?= (int) $record['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?= e($pageUrl) ?>"
                                        onsubmit="return confirm('Delete this dropdown value? If it is already used by an asset, it will be deactivated instead.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="type" value="<?= e($type) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-trash me-1"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>
