<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\DatabaseBackupService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

$backupService = new DatabaseBackupService();
$backups = $backupService->getBackups();

$pageTitle = 'Database Backups';

require_once dirname(__DIR__) . '/includes/app_header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Database Backup</h1>
        <p class="text-muted mb-0">
            Create and download compressed database backups.
        </p>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Database backup created successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger" role="alert">
            The requested backup operation could not be completed. Check the application error log for details.
        </div>
    <?php endif; ?>

    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        Backups contain sensitive company and user information. Keep downloaded copies in a secure location.
        Database restoration is not enabled yet.
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Create Backup</h2>
        </div>
        <div class="card-body">
            <p class="text-muted">
                AssetSys exports database table definitions, table records, and database triggers into a compressed file.
            </p>
            <form method="post" action="<?= e(base_url('/public/administration/database_backup_process.php')) ?>">
                <?= \App\Helpers\Csrf::field() ?>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-database me-1"></i>
                    Create Database Backup
                </button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Available Backups</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Backup File</th>
                            <th>Created</th>
                            <th>File Size</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($backups === []): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No database backups have been created.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td class="text-break">
                                    <i class="fa-solid fa-file-zipper text-primary me-2"></i>
                                    <?= e($backup['filename']) ?>
                                </td>
                                <td class="text-nowrap"><?= e($backup['modified_at']) ?></td>
                                <td><?= number_format($backup['size'] / 1048576, 2) ?> MB</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(base_url('/public/administration/database_backup_download.php?file=' . rawurlencode($backup['filename']))) ?>">
                                        <i class="fa-solid fa-download me-1"></i>
                                        Download
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/app_footer.php'; ?>
