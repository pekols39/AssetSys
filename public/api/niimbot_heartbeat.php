<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\\Models\\NiimbotDevice;
use App\\Models\\NiimbotPrintJob;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (int $status, bool $success, string $message): never {
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'message' => $message],
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

$authorizationHeader = trim((string) (
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));
$deviceId = trim((string) ($_SERVER['HTTP_X_NIIMBOT_DEVICE_ID'] ?? ''));

if (
    !preg_match('/\\ABearer\\s+([a-f0-9]{64})\\z/i', $authorizationHeader, $matches)
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
    $renewed = $jobs->renewActiveLeases($deviceId);

    $respond(200, true, 'Printer heartbeat accepted. Active leases renewed: ' . $renewed . '.');
} catch (Throwable $e) {
    error_log(
        'NIIMBOT heartbeat API error: '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );
    $respond(500, false, 'Unable to renew print job leases.');
}
