<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\AssetQrCode;
use App\Services\AuthorizationService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

AuthMiddleware::handle();

$authorization = new AuthorizationService();
$currentUser = $authorization->currentUser();

if ($currentUser === null) {
    http_response_code(401);
    exit('Authentication required.');
}

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);
    exit('You do not have permission to view asset QR codes.');
}

$assetId = filter_var(
    $_GET['asset_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($assetId === false || $assetId === null || $assetId <= 0) {
    http_response_code(400);
    exit('A valid asset ID is required.');
}

if (!$authorization->canAccessAsset((int) $assetId)) {
    http_response_code(403);
    exit('You do not have permission to access this asset.');
}

try {
    $qrModel = new AssetQrCode();
    $qrRecord = $qrModel->findByAssetId((int) $assetId);

    if (
        !is_array($qrRecord)
        || (int) ($qrRecord['is_active'] ?? 0) !== 1
    ) {
        http_response_code(404);
        exit('An active QR identity was not found.');
    }

    $token = (string) ($qrRecord['qr_token'] ?? '');

    if (
        strlen($token) !== 64
        || !ctype_xdigit($token)
    ) {
        http_response_code(500);
        exit('The QR identity is invalid.');
    }

    /*
     * The QR opens the scan page. That page must independently
     * authenticate the user and authorize access to the asset.
     */
    $scanUrl = base_url(
        '/public/assets/scan.php?token=' . rawurlencode($token)
    );

    $result = (new Builder(
        writer: new PngWriter(),
        data: $scanUrl,
        size: 320,
        margin: 10
    ))->build();

    header('Content-Type: image/png');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="asset-qr.png"');

    echo $result->getString();
    exit;
} catch (\Throwable $e) {
    error_log(
        'Asset QR image generation failed for asset ID '
        . (int) $assetId
        . ': '
        . get_class($e)
    );

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unable to generate the QR image.');
}
