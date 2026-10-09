<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\MaterialCategoryService;

header('Content-Type: application/json; charset=utf-8');

try {
    AuthMiddleware::handle();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);

        header('Allow: GET');

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    $service = new MaterialCategoryService();

    echo json_encode([
        'success' => true,
        'data' => $service->getActive(),
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
        'Material categories API error: '
        . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load material categories.',
    ]);
}