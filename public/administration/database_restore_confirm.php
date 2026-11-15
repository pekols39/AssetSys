<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\DatabaseBackupService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

$filename = $_GET['file'] ?? '';

if (!is_string($filename)) {
    http_response_code(400);
    exit('Invalid backup file.');
}

try {
    $path = (new DatabaseBackupService())->resolveBackup($filename);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Backup file not found.');
}

$fileSize = (int) filesize($path);
$modifiedAt = date('Y-m-d H:i:s', (int) filemtime($path));
$pageTitle = 'Confirm Database Restore';

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Confirm Database Restore</h1>
        <p class="text-muted mb-0">Review the selected backup before proceeding.</p>
    </div>

    <div class="alert alert-danger" role="alert">
        <strong>Warning: This operation is destructive.</strong>
        Restoring this backup will drop and recreate the database tables and replace their current records.
        Changes made after the selected backup was created will be lost.
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Backup file</dt>
                <dd class="col-sm-9 text-break"><?= e($filename) ?></dd>

                <dt class="col-sm-3">File size</dt>
                <dd class="col-sm-9"><?= number_format($fileSize / 1048576, 2) ?> MB</dd>

                <dt class="col-sm-3">Last modified</dt>
                <dd class="col-sm-9"><?= e($modifiedAt) ?></dd>
            </dl>
        </div>
    </div>

    <div class="alert alert-info">
        AssetSys will first create a separate safety backup of the current database.
        The restore will proceed only if the selected file passes the built-in backup validation.
        If an error occurs during execution, the database may be partially restored; use the safety backup to recover.
    </div>

    <form method="post" action="<?= e(base_url('/public/administration/database_restore_process.php')) ?>">
        <?= \App\Helpers\Csrf::field() ?>
        <input type="hidden" name="file" value="<?= e($filename) ?>">

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="confirm_restore" name="confirm_restore" required>
            <label class="form-check-label" for="confirm_restore">
                I understand that restoring this backup will replace the current database tables and records.
            </label>
        </div>

        <button type="submit" class="btn btn-danger">
            <i class="fa-solid fa-rotate-left me-1"></i>
            Confirm and Restore Database
        </button>
        <a href="<?= e(base_url('/public/administration/database_backups.php')) ?>" class="btn btn-outline-secondary ms-2">
            Cancel
        </a>
    </form>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>
