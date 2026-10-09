<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\MaterialCategoryService;

header('Content-Type: application/json; charset=utf-8');

try {
    AuthMiddleware::handle();

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

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    $service = new MaterialCategoryService();

    $categoryId = $service->create(
        $name,
        $code !== '' ? $code : null,
        $description !== '' ? $description : null
    );

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Material category created successfully.',
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
        'Material category create API error: '
        . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create material category.',
    ]);
}