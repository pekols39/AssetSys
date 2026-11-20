<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

try {
    AuthMiddleware::handle();

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);
        exit('Your session or security token has expired. Please refresh and try again.');
    }

    $authorization = new AuthorizationService();
    if (!$authorization->canAccessAssetOperations()) {
        http_response_code(403);
        exit('You do not have permission to import assets.');
    }

    $userId = Auth::id();
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);

    if ($userId === null || $batchId === false || $batchId <= 0) {
        throw new RuntimeException('Invalid import request.');
    }

    $result = (new AssetImportService())->processBatch((int) $batchId, (int) $userId);

    \App\Core\Session::set('swal_flash', [
        'icon' => ((int) $result['failed'] > 0) ? 'warning' : 'success',
        'title' => 'Asset import completed',
        'text' => number_format((int) $result['imported']) . ' asset(s) created; '
            . number_format((int) $result['failed']) . ' row(s) need review.',
    ]);
    header(
        'Location: ' . base_url(
            '/public/assets/import_batch.php?id=' . (int) $batchId
            . '&processed=1&imported=' . (int) $result['imported']
            . '&failed=' . (int) $result['failed']
        )
    );
    exit;
} catch (RuntimeException $exception) {
    \App\Core\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Asset import failed',
        'text' => $exception->getMessage(),
    ]);
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $target = ($batchId !== false && $batchId > 0)
        ? '/public/assets/import_batch.php?id=' . (int) $batchId
        : '/public/assets/import_history.php';
    header('Location: ' . base_url($target));
    exit;
} catch (Throwable $exception) {
    error_log('Asset import failed: ' . $exception->getMessage());
    \App\Core\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Asset import failed',
        'text' => 'Unable to import the asset records. Check the application error log.',
    ]);
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $target = ($batchId !== false && $batchId > 0)
        ? '/public/assets/import_batch.php?id=' . (int) $batchId
        : '/public/assets/import_history.php';
    header('Location: ' . base_url($target));
    exit;
}
