<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetDisposalService;

header('Content-Type: application/json; charset=utf-8');

try {
    AuthMiddleware::handle();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    $csrfToken =
        $_POST[CSRF_TOKEN_NAME]
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!Csrf::validate($csrfToken)) {
        throw new RuntimeException(
            'Invalid security token.'
        );
    }

    $disposalId =
        filter_input(
            INPUT_POST,
            'disposal_id',
            FILTER_VALIDATE_INT
        );

    if (!$disposalId || $disposalId <= 0) {
        throw new RuntimeException(
            'Invalid disposal record.'
        );
    }

    $service =
        new AssetDisposalService();

    $service->approve(
        (int) $disposalId,
        Auth::id()
    );

    echo json_encode([
        'success' => true,
        'message' =>
            'Disposal request approved successfully.',
    ]);

} catch (Throwable $e) {

    if (http_response_code() < 400) {
        http_response_code(400);
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}