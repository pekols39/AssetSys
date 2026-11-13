<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

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
        exit('You do not have permission to map import data.');
    }

    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
    if ($batchId === false || $batchId <= 0) {
        throw new RuntimeException('Invalid import batch.');
    }

    $mapping = $_POST['mapping'] ?? [];
    if (!is_array($mapping)) {
        throw new RuntimeException('Invalid mapping submission.');
    }

    (new AssetImportService())->saveBatchMapping((int) $batchId, $mapping);

    header('Location: ' . base_url('/public/assets/import_mapping.php?id=' . (int) $batchId . '&saved=1'));
    exit;
} catch (RuntimeException $exception) {
    http_response_code(422);
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Mapping failed</title></head><body><h1>Mapping failed</h1><p>' . $message . '</p><p><a href="' . htmlspecialchars(base_url('/public/assets/import_mapping.php?id=' . (int) ($_POST['batch_id'] ?? 0)), ENT_QUOTES, 'UTF-8') . '">Return to mappings</a></p></body></html>');
} catch (Throwable $exception) {
    error_log('Asset inventory mapping failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to save asset mappings. Check the application error log.');
}
