<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\MaterialCategoryService;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');

try {
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

        header('Allow: POST');

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

    if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired CSRF token.',
        ]);

        exit;
    }

    $categoryId = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($categoryId === false || $categoryId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid material category ID.',
        ]);

        exit;
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    $service = new MaterialCategoryService();

    $updated = $service->update(
        $categoryId,
        $name,
        $code !== '' ? $code : null,
        $description !== '' ? $description : null
    );

    if (!$updated) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Material category not found.',
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Material category updated successfully.',
        'data' => [
            'id' => $categoryId,
        ],
    ]);

} catch (RuntimeException $e) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    error_log(
        'Material category update API error: '
        . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update material category.',
    ]);
}