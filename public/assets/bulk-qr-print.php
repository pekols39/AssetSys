<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Session;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrCode;
use App\Models\NiimbotPrintJob;
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
    $showSwal('error', 'Access denied', 'You do not have permission to queue asset QR labels.');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    $showSwal('warning', 'Session expired', 'Refresh the Asset Register and try again.');
}

$userId = (int) ($currentUser['id'] ?? $currentUser['user_id'] ?? 0);
if ($userId <= 0) {
    $showSwal('error', 'Session expired', 'Please sign in again before printing QR labels.');
}

$rawFilters = is_array($_POST['filters'] ?? null) ? $_POST['filters'] : [];
$filterKeys = [
    'search', 'category_id', 'status_id', 'condition_id', 'location_id',
    'finance_owner_department', 'finance_main_location', 'finance_status',
    'finance_supplier', 'sort', 'direction',
];
$query = [];
foreach ($filterKeys as $key) $query[$key] = trim((string) ($rawFilters[$key] ?? ''));

$selectionMode = (string) ($_POST['selection_mode'] ?? 'selected');
$assetIds = [];
if ($selectionMode === 'selected') {
    $rawIds = $_POST['asset_ids'] ?? [];
    if (!is_array($rawIds)) {
        $showSwal('error', 'Invalid selection', 'Please select valid assets and try again.');
    }
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) $assetIds[(int) $id] = (int) $id;
    }
} elseif ($selectionMode === 'all_filtered') {
    // "Print all filtered" remains available, but the user can select a smaller
    // subset (for example, 10 checkboxes) to queue only those labels.
    $excluded = [];
    foreach ((array) ($_POST['excluded_ids'] ?? []) as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id !== false && $id !== null && $id > 0) $excluded[(int) $id] = true;
    }
    $assetModel = new Asset();
    $page = 1;
    do {
        $result = $assetModel->getPage($query, null, $page, 100);
        foreach ($result['items'] as $asset) {
            $id = (int) ($asset['id'] ?? 0);
            if ($id > 0 && !isset($excluded[$id])) $assetIds[$id] = $id;
        }
        $page++;
    } while ($page <= (int) ($result['total_pages'] ?? 1));
} else {
    $showSwal('error', 'Invalid selection', 'Please select assets again and retry.');
}

if ($assetIds === []) {
    $showSwal('info', 'No assets selected', 'Select the QR labels you want to print.');
}

set_time_limit(0);
$assetModel = new Asset();
$qrModel = new AssetQrCode();
$jobs = new NiimbotPrintJob();
$queued = 0;
$skipped = 0;
$failed = 0;

foreach ($assetIds as $assetId) {
    try {
        if (!$authorization->canAccessAsset($assetId)) {
            $skipped++;
            continue;
        }
        $asset = $assetModel->findById($assetId);
        $qr = $qrModel->findByAssetId($assetId);
        if (!is_array($asset) || !is_array($qr) || (int) ($qr['is_active'] ?? 0) !== 1) {
            $skipped++;
            continue;
        }
        $jobs->create(
            $assetId,
            (int) $qr['id'],
            $userId,
            trim((string) ($asset['asset_name'] ?? 'Unnamed Asset')),
            trim((string) ($asset['asset_code'] ?? '')),
            isset($asset['asset_tag']) ? trim((string) $asset['asset_tag']) : null,
            isset($asset['finance_ffe_number']) ? trim((string) $asset['finance_ffe_number']) : null
        );
        $queued++;
    } catch (Throwable $e) {
        $failed++;
        error_log('Bulk QR print queue failed for asset ID ' . $assetId . ': ' . get_class($e) . ' — ' . $e->getMessage());
    }
}

$showSwal(
    $failed > 0 || $skipped > 0 ? 'warning' : 'success',
    'Print batch queued',
    sprintf('Print jobs queued: %d. Skipped (missing or inactive QR identity): %d. Failed: %d. The NIIMBOT agent will process the queue.', $queued, $skipped, $failed)
);
