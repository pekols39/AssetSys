<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialCategoryService;

AuthMiddleware::handle();

$authorization = new AuthorizationService();

if (!$authorization->canAccessAssetMaterialAreas()) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to access this resource.',
    ]);

    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
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
            'message' => 'Invalid or expired CSRF token.',
        ]);

        exit;
    }

    $authorization = new AuthorizationService();

    if (!$authorization->canAccessAssetMaterialAreas()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Authorized Asset Manager access required.',
        ]);

        exit;
    }

    $id = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($id === false || $id <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid material category ID.',
        ]);

        exit;
    }

    $action = trim(
        (string) ($_POST['action'] ?? '')
    );

    if (!in_array($action, ['activate', 'deactivate'], true)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid category status action.',
        ]);

        exit;
    }

    $categoryService = new MaterialCategoryService();

    $category = $categoryService->get($id);

    if ($category === null) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Material category not found.',
        ]);

        exit;
    }

    if ($action === 'deactivate') {

        $categoryService->deactivate($id);

        echo json_encode([
            'success' => true,
            'message' => 'Material category deactivated successfully.',
            'data' => [
                'id' => $id,
                'is_active' => false,
            ],
        ]);

        exit;
    }

    $categoryService->activate($id);

    echo json_encode([
        'success' => true,
        'message' => 'Material category activated successfully.',
        'data' => [
            'id' => $id,
            'is_active' => true,
        ],
    ]);

} catch (\RuntimeException $e) {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (\Throwable $e) {

    http_response_code(500);

    error_log(
        'Material category status error: '
        . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update material category status.',
    ]);
}