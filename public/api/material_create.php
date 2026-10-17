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

if (!$authorization->canAccessAssetMaterialAreas()) {
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
    Csrf::validate(
        $_POST['csrf_token'] ?? null
    );

    $categoryId = (int) (
        $_POST['category_id'] ?? 0
    );

    $code = trim(
        (string) (
            $_POST['code'] ?? ''
        )
    );

    $name = trim(
        (string) (
            $_POST['name'] ?? ''
        )
    );

    $unit = trim(
        (string) (
            $_POST['unit'] ?? ''
        )
    );

    $description = isset($_POST['description'])
        ? trim((string) $_POST['description'])
        : null;

    $minimumStockLevel = isset(
        $_POST['minimum_stock_level']
    )
        ? (float) $_POST['minimum_stock_level']
        : 0.0;

    $reorderLevel = isset(
        $_POST['reorder_level']
    )
        ? (float) $_POST['reorder_level']
        : 0.0;

    $isReturnable =
        isset($_POST['is_returnable'])
        && (string) $_POST['is_returnable'] === '1';

    $materialService = new MaterialService();

    $materialId = $materialService->create(
        categoryId: $categoryId,
        code: $code,
        name: $name,
        unit: $unit,
        description: $description,
        minimumStockLevel: $minimumStockLevel,
        reorderLevel: $reorderLevel,
        isReturnable: $isReturnable
    );

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Material created successfully.',
        'data' => [
            'id' => $materialId,
        ],
    ]);

} catch (RuntimeException $e) {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (Throwable $e) {

    error_log(
        'Material create error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create material.',
    ]);
}