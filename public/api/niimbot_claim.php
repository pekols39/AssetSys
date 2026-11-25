<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Models\AssetQrCode;
use App\Models\NiimbotPrintJob;
use App\Models\NiimbotDevice;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (int $status, bool $success, string $message, array $data = []): never {
    http_response_code($status);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, false, 'Method not allowed.');
}

if (
    APP_ENV === 'production'
    && (
        empty($_SERVER['HTTPS'])
        || strtolower((string) $_SERVER['HTTPS']) === 'off'
    )
    && (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 443
) {
    $respond(403, false, 'HTTPS is required.');
}

$authorizationHeader = trim(
    (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    )
);
$deviceId = trim((string) ($_SERVER['HTTP_X_NIIMBOT_DEVICE_ID'] ?? ''));

if (
    !preg_match('/\ABearer\s+([a-f0-9]{64})\z/i', $authorizationHeader, $matches)
    || !in_array($deviceId, ['property-custodian-b1', 'it-laptop-b1'], true)
) {
    $respond(401, false, 'Invalid device credentials.');
}

try {
    $devices = new NiimbotDevice();

    if (!$devices->authenticate($deviceId, $matches[1])) {
        $respond(401, false, 'Invalid device credentials.');
    }

    $jobs = new NiimbotPrintJob();
    $qrCodes = new AssetQrCode();
    $claimedJobs = $jobs->claimBatch($deviceId, 5);
    $printableJobs = [];

    foreach ($claimedJobs as $job) {
        $jobId = (int) ($job['id'] ?? 0);
        $assetId = (int) ($job['asset_id'] ?? 0);
        $qrCodeId = (int) ($job['asset_qr_code_id'] ?? 0);

        $qrRecord = $qrCodes->findActiveByIdForAsset($qrCodeId, $assetId);
        $qrToken = is_array($qrRecord)
            ? (string) ($qrRecord['qr_token'] ?? '')
            : '';

        if (
            !is_array($qrRecord)
            || (int) ($qrRecord['id'] ?? 0) !== $qrCodeId
            || (int) ($qrRecord['asset_id'] ?? 0) !== $assetId
            || !preg_match('/\A[a-f0-9]{64}\z/i', $qrToken)
        ) {
            $jobs->fail(
                $jobId,
                $deviceId,
                'The QR identity is no longer active or does not match the queued asset.'
            );
            continue;
        }

        $scanUrl = base_url('/assets/scan.php?token=' . rawurlencode($qrToken));
        $qrPng = (new Builder(
            writer: new PngWriter(),
            data: $scanUrl,
            size: 320,
            margin: 10
        ))->build()->getString();

        $printableJobs[] = [
            'id' => $jobId,
            'asset_id' => $assetId,
            'asset_name' => (string) $job['asset_name'],
            'asset_code' => (string) $job['asset_code'],
            'asset_tag' => $job['asset_tag'] !== null
                ? (string) $job['asset_tag']
                : null,
            'finance_ffe_number' => $job['finance_ffe_number'] !== null
                ? (string) $job['finance_ffe_number']
                : null,
            'finance_owner_department' => $job['finance_owner_department'] !== null
                ? (string) $job['finance_owner_department']
                : null,
            'attempts' => (int) $job['attempts'],
            'scan_url' => $scanUrl,
            'qr_image_mime' => 'image/png',
            'qr_image_base64' => base64_encode($qrPng),
        ];
    }

    $respond(200, true, $printableJobs
        ? 'Print batch claimed.'
        : 'No pending print jobs for this station.', [
            'jobs' => $printableJobs,
        ]);

} catch (Throwable $e) {
    error_log(
        'NIIMBOT claim API error: '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );

    $respond(500, false, 'Unable to claim print jobs.');
}
