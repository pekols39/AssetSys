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

    header(
        'Location: ' . base_url(
            '/public/assets/import_batch.php?id=' . (int) $batchId
            . '&processed=1&imported=' . (int) $result['imported']
            . '&failed=' . (int) $result['failed']
        )
    );
    exit;
} catch (RuntimeException $exception) {
    http_response_code(422);
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Import failed</title></head><body><h1>Import failed</h1><p>' . $message . '</p><p><a href="' . htmlspecialchars(base_url('/public/assets/index.php'), ENT_QUOTES, 'UTF-8') . '">Return to Assets</a></p></body></html>');
} catch (Throwable $exception) {
    error_log('Finance asset import failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to import the Finance inventory. Check the application error log.');
}
