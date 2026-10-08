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

$scheduleId = filter_input(
    INPUT_POST,
    'schedule_id',
    FILTER_VALIDATE_INT
);

if (!$scheduleId || $scheduleId < 1) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid depreciation schedule.',
    ]);

    exit;
}

try {

    $service = new DepreciationService();

    $entry = $service->postNextEntry(
        (int) $scheduleId,
        (int) Auth::id()
    );

    echo json_encode([
        'success' => true,
        'message' => 'Depreciation entry posted successfully.',
        'data' => $entry,
    ]);

} catch (\Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}