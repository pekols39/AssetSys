<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrCode;
use App\Models\NiimbotPrintJob;
use App\Services\AssetQrCodeService;
use App\Services\AssetService;
use App\Services\AuthorizationService;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to generate or print asset QR labels.');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    http_response_code(419);
    exit('Your security token is invalid or expired. Refresh the page and try again.');
}

$requestedBy = (int) ($currentUser['user_id'] ?? 0);
if ($requestedBy <= 0) {
    http_response_code(401);
    exit('Unable to identify the requesting user.');
}

$rawFilters = is_array($_POST['filters'] ?? null) ? $_POST['filters'] : [];
$filterKeys = [
    'search', 'category_id', 'status_id', 'condition_id', 'location_id',
    'finance_owner_department', 'finance_main_location', 'finance_status',
    'finance_supplier', 'sort', 'direction',
];
$filters = [];
foreach ($filterKeys as $key) {
    $filters[$key] = trim((string) ($rawFilters[$key] ?? ''));
}

$selectionMode = (string) ($_POST['selection_mode'] ?? 'selected');
$selectedIds = [];
if ($selectionMode === 'selected') {
    $rawIds = $_POST['asset_ids'] ?? [];
    if (!is_array($rawIds)) {
        http_response_code(400);
        exit('Invalid asset selection.');
    }
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) {
            $selectedIds[(int) $id] = (int) $id;
        }
    }
    if ($selectedIds === []) {
        http_response_code(400);
        exit('Select at least one asset.');
    }
} elseif ($selectionMode !== 'all_filtered') {
    http_response_code(400);
    exit('Invalid selection mode.');
}

$excludedIds = [];
if ($selectionMode === 'all_filtered' && is_array($_POST['excluded_ids'] ?? null)) {
    foreach ($_POST['excluded_ids'] as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) {
            $excludedIds[(int) $id] = true;
        }
    }
}

set_time_limit(0);

$assetService = new AssetService();
$assetModel = new Asset();
$qrModel = new AssetQrCode();
$qrService = new AssetQrCodeService();
$jobModel = new NiimbotPrintJob();

$created = 0;
$queued = 0;
$skipped = 0;
$failed = 0;

$processAsset = static function (int $assetId) use (
    $authorization, $assetModel, $qrModel, $qrService, $jobModel,
    $requestedBy, &$created, &$queued, &$skipped, &$failed
): void {
    try {
        if (!$authorization->canAccessAsset($assetId)) {
            $skipped++;
            return;
        }

        $asset = $assetModel->findById($assetId);
        if (!is_array($asset)) {
            $skipped++;
            return;
        }

        $existingQr = $qrModel->findByAssetId($assetId);
        if (is_array($existingQr) && (int) ($existingQr['is_active'] ?? 0) !== 1) {
            $skipped++;
            return;
        }

        $qrRecord = $qrService->createForAsset($assetId);
        if (!is_array($existingQr)) {
            $created++;
        }

        $token = (string) ($qrRecord['qr_token'] ?? '');
        if (
            (int) ($qrRecord['is_active'] ?? 0) !== 1
            || !preg_match('/\\A[a-f0-9]{64}\\z/i', $token)
        ) {
            $skipped++;
            return;
        }

        $jobModel->create(
            $assetId,
            (int) $qrRecord['id'],
            $requestedBy,
            trim((string) ($asset['asset_name'] ?? 'Unnamed Asset')),
            trim((string) ($asset['asset_code'] ?? '')),
            isset($asset['asset_tag']) ? trim((string) $asset['asset_tag']) : null,
            isset($asset['finance_ffe_number']) ? trim((string) $asset['finance_ffe_number']) : null
        );
        $queued++;
    } catch (Throwable $e) {
        $failed++;
        error_log(
            'Bulk asset QR processing failed for asset ID '
            . $assetId . ': ' . get_class($e) . ' — ' . $e->getMessage()
        );
    }
};

if ($selectionMode === 'selected') {
    foreach ($selectedIds as $assetId) {
        $processAsset($assetId);
    }
} else {
    // Process all matching records in pages of 100; no application-level cap.
    $page = 1;
    do {
        $result = $assetService->getPage($filters, null, $page, 100);
        foreach ($result['items'] as $asset) {
            $assetId = (int) ($asset['id'] ?? 0);
            if ($assetId > 0 && !isset($excludedIds[$assetId])) {
                $processAsset($assetId);
            }
        }
        $page++;
    } while ($page <= (int) ($result['total_pages'] ?? 1));
}

$query = $filters;
$query['page'] = 1;
$query['bulk_qr'] = '1';
$query['created'] = $created;
$query['queued'] = $queued;
$query['skipped'] = $skipped;
$query['failed'] = $failed;

header(
    'Location: ' . base_url('/public/assets/index.php?' . http_build_query($query)),
    true,
    303
);
exit;
