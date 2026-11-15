<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

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

$filename = $_GET['file'] ?? '';

if (!is_string($filename)) {
    http_response_code(400);
    exit('Invalid backup file.');
}

try {
    $path = (new DatabaseBackupService())->resolveBackup($filename);

    try {
        (new AuditLogService())->log(
            action: 'download_database_backup',
            module: 'administration',
            entityType: 'database_backup',
            description: 'Downloaded a SQL database backup.',
            newValues: ['filename' => $filename]
        );
    } catch (Throwable $auditError) {
        error_log('Database backup download audit logging failed: ' . $auditError->getMessage());
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    exit('Backup file not found.');
}
