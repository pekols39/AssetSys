<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Services\MaintenanceService;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

if (
    !Csrf::validate(
        $_POST[CSRF_TOKEN_NAME] ?? null
    )
) {
    http_response_code(419);

    echo json_encode([
        'success' => false,
        'message' => 'Security token not found. Please refresh the page.',
    ]);

    exit;
}

$userId = Auth::id();

if ($userId <= 0) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.',
    ]);

    exit;
}

try {
    $service = new MaintenanceService();

    $maintenanceId = $service->create(
        $_POST,
        $userId
    );

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance record created successfully.',
        'maintenance_id' => $maintenanceId,
    ]);

} catch (RuntimeException $e) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (\Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create maintenance record.',
    ]);
}