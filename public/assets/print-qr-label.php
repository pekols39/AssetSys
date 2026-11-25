<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Models\Asset;
use App\Models\AssetQrCode;
use App\Models\NiimbotPrintJob;
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
    exit('You do not have permission to print asset QR labels.');
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    http_response_code(419);
    exit('Your security token is invalid or expired. Refresh the page and try again.');
}

$assetId = filter_var(
    $_POST['asset_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($assetId === false || $assetId === null || $assetId <= 0) {
    http_response_code(400);
    exit('A valid asset ID is required.');
}

$assetId = (int) $assetId;

if (!$authorization->canAccessAsset($assetId)) {
    http_response_code(403);
    exit('You do not have permission to access this asset.');
}

try {
    $assetModel = new Asset();
    $asset = $assetModel->findById($assetId);

    if (!is_array($asset)) {
        http_response_code(404);
        exit('Asset not found.');
    }

    $qrModel = new AssetQrCode();
    $qrRecord = $qrModel->findByAssetId($assetId);

    if (
        !is_array($qrRecord)
        || (int) ($qrRecord['is_active'] ?? 0) !== 1
        || !preg_match(
            '/\A[a-f0-9]{64}\z/i',
            (string) ($qrRecord['qr_token'] ?? '')
        )
    ) {
        http_response_code(409);
        exit('This asset does not have an active QR identity.');
    }

    $requestedBy = (int) ($currentUser['user_id'] ?? 0);
    $roleName = (string) ($currentUser['role_name'] ?? '');

    if ($requestedBy <= 0) {
        http_response_code(401);
        exit('Unable to identify the requesting user.');
    }

    // Assign jobs from the authenticated account's operational role.
    // Never accept a target printer from a browser form or query string.
    $targetDeviceId = match ($roleName) {
        'Property Custodian' => 'property-custodian-b1',
        'Asset Manager' => 'it-laptop-b1',
        default => null,
    };

    if ($targetDeviceId === null) {
        http_response_code(403);
        exit('Your account is not assigned to a NIIMBOT print station.');
    }

    $jobModel = new NiimbotPrintJob();

    $jobId = $jobModel->create(
        $assetId,
        (int) $qrRecord['id'],
        $requestedBy,
        $targetDeviceId,
        trim((string) ($asset['asset_name'] ?? 'Unnamed Asset')),
        trim((string) ($asset['asset_code'] ?? '')),
        isset($asset['asset_tag'])
            ? trim((string) $asset['asset_tag'])
            : null,
        isset($asset['finance_ffe_number'])
            ? trim((string) $asset['finance_ffe_number'])
            : null
    );

    header(
        'Location: ' . base_url(
            '/assets/qr.php?asset_id=' . $assetId
            . '&queued=1&job_id=' . $jobId
        ),
        true,
        303
    );
    exit;

} catch (Throwable $e) {
    error_log(
        'NIIMBOT print queue error for asset ID '
        . $assetId
        . ': '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to queue the NIIMBOT print request. Please try again or contact the administrator.');
}
