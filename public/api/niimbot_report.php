<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Models\NiimbotPrintJob;
use App\Models\NiimbotDevice;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respond = static function (
    int $status,
    bool $success,
    string $message
): never {
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
    || !in_array($deviceId, ['property-custodian-b1'], true)
) {
    $respond(401, false, 'Invalid device credentials.');
}

try {
    $devices = new NiimbotDevice();

    if (!$devices->authenticate($deviceId, $matches[1])) {
        $respond(401, false, 'Invalid device credentials.');
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || strlen($rawBody) > 8192) {
        $respond(400, false, 'Invalid request body.');
    }

    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        $respond(400, false, 'A valid JSON request body is required.');
    }

    $jobId = filter_var($payload['job_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $payload['status'] ?? null;

    if ($status !== 'release_unstarted' && ($jobId === false || $jobId === null || $jobId <= 0)) {
        $respond(422, false, 'A valid job ID is required.');
    }

    if (!is_string($status) || !in_array($status, ['completed', 'failed', 'release_unstarted'], true)) {
        $respond(422, false, 'A valid job status is required.');
    }

    $jobs = new NiimbotPrintJob();

    if ($status === 'completed') {
        $updated = $jobs->complete((int) $jobId, $deviceId);
    } elseif ($status === 'release_unstarted') {
        $jobIds = $payload['job_ids'] ?? null;
        if (!is_array($jobIds) || count($jobIds) > 5) {
            $respond(422, false, 'A list of up to five job IDs is required.');
        }
        foreach ($jobIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                $respond(422, false, 'Every released job ID must be a positive integer.');
            }
        }
        $released = $jobs->releaseUnstarted($jobIds, $deviceId);
        $updated = true;
    } else {
        $errorMessage = $payload['error_message'] ?? '';

        if (!is_string($errorMessage)) {
            $respond(422, false, 'The error message must be text.');
        }

        $updated = $jobs->fail((int) $jobId, $deviceId, $errorMessage);
    }

    if (!$updated) {
        $respond(
            409,
            false,
            'The job was not found, is no longer processing, or belongs to another device.'
        );
    }

    $respond(
        200,
        true,
        $status === 'completed'
            ? 'Print job completion recorded.'
            : ($status === 'release_unstarted'
                ? 'Unstarted batch jobs returned to the pending queue: ' . $released . '.'
                : 'Print job failure recorded.')
    );

} catch (Throwable $e) {
    error_log(
        'NIIMBOT report API error: '
        . get_class($e)
        . ' — '
        . $e->getMessage()
    );

    $respond(500, false, 'Unable to update the print job.');
}
