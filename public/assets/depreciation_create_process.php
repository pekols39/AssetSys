<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\DepreciationService;

AuthMiddleware::handle();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
    http_response_code(419);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired security token.',
    ]);

    exit;
}

$assetId = filter_input(
    INPUT_POST,
    'asset_id',
    FILTER_VALIDATE_INT
);

if (!$assetId || $assetId < 1) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid asset.',
    ]);

    exit;
}

$startDate = trim(
    (string) ($_POST['start_date'] ?? '')
);

if ($startDate === '') {
    $startDate = null;
}

try {

    $service = new DepreciationService();

    $schedule = $service->createSchedule(
        (int) $assetId,
        (int) Auth::id(),
        $startDate
    );

    echo json_encode([
        'success' => true,
        'message' => 'Depreciation schedule created successfully.',
        'data' => $schedule,
    ]);

} catch (\Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}