<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Session;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\AssetQrCode;
use App\Services\AssetQrCodeService;
use App\Services\AssetService;
use App\Services\AuthorizationService;

$returnTo = base_url('/public/assets/index.php');
$showSwal = static function (string $icon, string $title, string $message) use ($returnTo): never {
    Session::set('swal_flash', ['icon' => $icon, 'title' => $title, 'text' => $message]);
    header('Location: ' . $returnTo, true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $showSwal('error', 'Invalid request', 'Please submit this action from the Asset Register.');
}

AuthMiddleware::handle();
$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null || !$authorization->canAccessAssetOperations()) {
    $showSwal('error', 'Access denied', 'You do not have permission to generate asset QR identities.');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    $showSwal('warning', 'Session expired', 'Refresh the Asset Register and try again.');
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
        $showSwal('error', 'Invalid selection', 'Please select valid assets and try again.');
    }
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) $selectedIds[(int) $id] = (int) $id;
    }
    if ($selectedIds === []) {
        $showSwal('info', 'No assets selected', 'Select at least one asset to continue.');
    }
} elseif ($selectionMode !== 'all_filtered') {
    $showSwal('error', 'Invalid selection', 'Please select assets again and retry.');
}

$excludedIds = [];
if ($selectionMode === 'all_filtered' && is_array($_POST['excluded_ids'] ?? null)) {
    foreach ($_POST['excluded_ids'] as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) $excludedIds[(int) $id] = true;
    }
}

set_time_limit(0);
$assetService = new AssetService();
$qrService = new AssetQrCodeService();
$qrModel = new AssetQrCode();
$created = 0;
$reused = 0;
$skipped = 0;
$failed = 0;

$processAsset = static function (int $assetId) use (
    $authorization, $qrService, $qrModel, &$created, &$reused, &$skipped, &$failed
): void {
    try {
        if (!$authorization->canAccessAsset($assetId)) {
            $skipped++;
            return;
        }
        $existingQr = $qrModel->findByAssetId($assetId);
        if (is_array($existingQr) && (int) ($existingQr['is_active'] ?? 0) !== 1) {
            $skipped++;
            return;
        }

        // Reuse the existing secure QR identity when active; otherwise let the
        // service create a new permanent token and its audit entry.
        $qrRecord = $qrService->createForAsset($assetId);
        if ((int) ($qrRecord['is_active'] ?? 0) !== 1) {
            $skipped++;
            return;
        }

        if (is_array($existingQr)) {
            $reused++;
        } else {
            $created++;
        }
    } catch (Throwable $e) {
        $failed++;
        error_log('Bulk QR generation failed for asset ID ' . $assetId . ': ' . get_class($e) . ' — ' . $e->getMessage());
    }
};

if ($selectionMode === 'selected') {
    foreach ($selectedIds as $assetId) $processAsset($assetId);
} else {
    $page = 1;
    do {
        $result = $assetService->getPage($filters, null, $page, 100);
        foreach ($result['items'] as $asset) {
            $assetId = (int) ($asset['id'] ?? 0);
            if ($assetId > 0 && !isset($excludedIds[$assetId])) $processAsset($assetId);
        }
        $page++;
    } while ($page <= (int) ($result['total_pages'] ?? 1));
}

$showSwal(
    $failed > 0 ? 'warning' : 'success',
    'QR generation completed',
    sprintf('New QR identities: %d. Existing identities reused: %d. Skipped: %d. Failed: %d. No print jobs were created.', $created, $reused, $skipped, $failed)
);
