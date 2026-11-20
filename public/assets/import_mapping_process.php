<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\\Helpers\\Csrf;
use App\\Middleware\\AuthMiddleware;
use App\\Services\\AuthorizationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

AuthMiddleware::handle();

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    \\App\\Core\\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Session expired',
        'text' => 'Refresh the import preview and try again.',
    ]);
    header('Location: ' . base_url('/public/assets/import_history.php'));
    exit;
}

$authorization = new AuthorizationService();
if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to access asset imports.');
}

$batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
if ($batchId === false || $batchId <= 0) {
    \\App\\Core\\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Invalid import batch',
        'text' => 'Please open a valid import preview.',
    ]);
    header('Location: ' . base_url('/public/assets/import_history.php'));
    exit;
}

\\App\\Core\\Session::set('swal_flash', [
    'icon' => 'info',
    'title' => 'Manual mapping removed',
    'text' => 'Category and status values are now matched or created automatically from the workbook.',
]);
header('Location: ' . base_url('/public/assets/import_batch.php?id=' . (int) $batchId));
exit;
