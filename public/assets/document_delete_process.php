<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetFileService;

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

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? '';

    if (!is_string($csrfToken) || $csrfToken === '') {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    if (!Csrf::validate(
        is_string($csrfToken) ? $csrfToken : null
    )) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid security token.',
        ]);

        exit;
    }

    $documentId = filter_input(
        INPUT_POST,
        'document_id',
        FILTER_VALIDATE_INT
    );

    if (!$documentId || $documentId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid document.',
        ]);

        exit;
    }

    $fileService = new AssetFileService();

    $fileService->deleteDocument(
        (int) $documentId
    );

    echo json_encode([
        'success' => true,
        'message' => 'Document deleted successfully.',
    ]);
} catch (\Throwable $e) {
    error_log(
        'Asset document delete error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete the document.',
    ]);
}