<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthorizationService;
use App\Services\DatabaseRestoreService;

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

$filename = $_POST['file'] ?? '';

if (
    !is_string($filename)
    || ($_POST['confirm_restore'] ?? '') !== '1'
) {
    http_response_code(400);
    exit('Restore confirmation is required.');
}

try {
    $result = (new DatabaseRestoreService())->restoreBackup($filename);

    try {
        (new AuditLogService())->log(
            action: 'restore_database_backup',
            module: 'administration',
            entityType: 'database_backup',
            description: 'Restored the database from a validated SQL backup.',
            newValues: [
                'filename' => $result['filename'],
                'safety_backup' => $result['safety_backup'],
                'tables' => $result['tables'],
                'statements' => $result['statements'],
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Database restore audit logging failed: ' . $auditError->getMessage());
    }

    header(
        'Location: ' . base_url(
            '/public/administration/database_backups.php?restored=1&safety='
            . rawurlencode($result['safety_backup'])
        )
    );
    exit;
} catch (Throwable $e) {
    error_log('Database restore failed: ' . $e->getMessage());

    try {
        (new AuditLogService())->log(
            action: 'restore_database_backup',
            module: 'administration',
            entityType: 'database_backup',
            description: 'Database restore failed or was stopped.',
            result: 'failure',
            newValues: [
                'filename' => $filename,
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Database restore failure audit logging failed: ' . $auditError->getMessage());
    }

    header('Location: ' . base_url('/public/administration/database_backups.php?restore_error=1'));
    exit;
}
