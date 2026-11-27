<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
if (!$authorization->canManageAssets()) {
    http_response_code(403);
    exit('You do not have permission to access asset imports.');
}

$batchId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($batchId === false || $batchId <= 0) {
    \App\Core\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Invalid import batch',
        'text' => 'Please open a valid import preview.',
    ]);
    header('Location: ' . base_url('/public/assets/import_history.php'));
    exit;
}

\App\Core\Session::set('swal_flash', [
    'icon' => 'info',
    'title' => 'Manual mapping removed',
    'text' => 'Categories and statuses are now matched or created automatically from the Excel workbook.',
]);
header('Location: ' . base_url('/public/assets/import_batch.php?id=' . (int) $batchId));
exit;
