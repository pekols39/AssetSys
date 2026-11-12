<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Services\AssetImportService;
use App\Services\AuthorizationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

try {
    \App\Middleware\AuthMiddleware::handle();

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);
        exit('Your session or security token has expired. Please refresh and try again.');
    }

    $authorization = new AuthorizationService();
    if (!$authorization->canAccessAssetOperations()) {
        http_response_code(403);
        exit('You do not have permission to import inventory.');
    }

    $userId = Auth::id();
    if ($userId === null) {
        http_response_code(401);
        exit('Authentication required.');
    }

    if (!isset($_FILES['finance_workbook'])) {
        throw new RuntimeException('Please select a Finance workbook.');
    }

    $upload = $_FILES['finance_workbook'];

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The workbook upload failed. Please try again.');
    }

    if ((int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('The workbook must be larger than zero and no bigger than 20 MB.');
    }

    $batchId = (new AssetImportService())->stageWorkbook(
        (string) $upload['tmp_name'],
        (string) $upload['name'],
        (int) $userId
    );

    header('Location: ' . base_url('/public/assets/import_batch.php?id=' . $batchId));
    exit;
} catch (RuntimeException $exception) {
    http_response_code(422);
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Import failed</title></head><body><h1>Import failed</h1><p>' . $message . '</p><p><a href="' . htmlspecialchars(base_url('/public/assets/import.php'), ENT_QUOTES, 'UTF-8') . '">Return to import</a></p></body></html>');
} catch (Throwable $exception) {
    error_log('Finance inventory staging failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to process the Finance workbook. Check the application error log.');
}
