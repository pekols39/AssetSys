<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\MaterialService;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetOperations()) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to access this resource.',
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

try {
    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.',
        ]);

        exit;
    }

    $materialId = (int) (
        $_POST['id'] ?? 0
    );

    $action = trim(
        (string) (
            $_POST['action'] ?? ''
        )
    );

    if ($materialId <= 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid material ID.',
        ]);

        exit;
    }

    if (
        $action !== 'activate'
        && $action !== 'deactivate'
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid material status action.',
        ]);

        exit;
    }

    $materialService = new MaterialService();

    if ($action === 'activate') {
        $updated = $materialService->activate(
            $materialId
        );

        $message = 'Material activated successfully.';
    } else {
        $updated = $materialService->deactivate(
            $materialId
        );

        $message = 'Material deactivated successfully.';
    }

    if (!$updated) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to update material status.',
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'id' => $materialId,
            'action' => $action,
        ],
    ]);

} catch (\RuntimeException $e) {

    $message = $e->getMessage();

    if ($message === 'Material not found.') {
        http_response_code(404);
    } else {
        http_response_code(403);
    }

    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);

} catch (\Throwable $e) {

    error_log(
        'Material status error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update material status.',
    ]);
}