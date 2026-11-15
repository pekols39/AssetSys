<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthorizationService;
use App\Services\DatabaseBackupService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->isAdministrator()) {
    http_response_code(403);
    exit('403 Forbidden: Administrator access required.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    http_response_code(419);
    exit('Invalid or expired security token. Refresh the page and try again.');
}

try {
    $backup = (new DatabaseBackupService())->createBackup();

    try {
        (new AuditLogService())->log(
            action: 'create_database_backup',
            module: 'administration',
            entityType: 'database_backup',
            description: 'Created a SQL database backup.',
            newValues: [
                'filename' => $backup['filename'],
                'size_bytes' => $backup['size'],
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Database backup audit logging failed: ' . $auditError->getMessage());
    }

    header('Location: ' . base_url('/public/administration/database_backups.php?success=1'));
    exit;
} catch (Throwable $e) {
    error_log('Database backup creation failed: ' . $e->getMessage());

    try {
        (new AuditLogService())->log(
            action: 'create_database_backup',
            module: 'administration',
            entityType: 'database_backup',
            description: 'SQL database backup creation failed.',
            result: 'failure'
        );
    } catch (Throwable $auditError) {
        error_log('Database backup failure audit logging failed: ' . $auditError->getMessage());
    }

    header('Location: ' . base_url('/public/administration/database_backups.php?error=1'));
    exit;
}
