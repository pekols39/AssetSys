<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
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

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired security token.',
        ]);

        exit;
    }

    $assetId = (int) ($_POST['asset_id'] ?? 0);

    if ($assetId <= 0) {
        throw new RuntimeException('Invalid asset.');
    }

    if (
        !isset($_FILES['document'])
        || !is_array($_FILES['document'])
    ) {
        throw new RuntimeException('No document was uploaded.');
    }

    $documentType = isset($_POST['document_type'])
        ? (string) $_POST['document_type']
        : '';

    $description = isset($_POST['description'])
        ? (string) $_POST['description']
        : null;

    $service = new AssetFileService();

    $documentId = $service->uploadDocument(
        $assetId,
        (int) Auth::id(),
        $_FILES['document'],
        $documentType,
        $description
    );

    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully.',
        'document_id' => $documentId,
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}