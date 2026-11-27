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
    if (!$authorization->canManageAssets()) {
        http_response_code(403);
        exit('You do not have permission to import inventory.');
    }

    $userId = Auth::id();
    if ($userId === null) {
        http_response_code(401);
        exit('Authentication required.');
    }

    if (!isset($_FILES['finance_workbook'])) {
        throw new RuntimeException('Please select a asset workbook.');
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

    \App\Core\Session::set('swal_flash', [
        'icon' => 'success',
        'title' => 'Workbook staged',
        'text' => 'The source records are ready for review. No assets have been created yet.',
    ]);
    header('Location: ' . base_url('/public/assets/import_batch.php?id=' . $batchId));
    exit;
 } catch (RuntimeException $exception) {
    \App\Core\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Import failed',
        'text' => $exception->getMessage(),
    ]);
    header('Location: ' . base_url('/public/assets/import.php'));
    exit;
} catch (Throwable $exception) {
    error_log('Asset inventory staging failed: ' . $exception->getMessage());
    \App\Core\Session::set('swal_flash', [
        'icon' => 'error',
        'title' => 'Import failed',
        'text' => 'Unable to process the asset workbook. Check the application error log.',
    ]);
    header('Location: ' . base_url('/public/assets/import.php'));
    exit;
}
