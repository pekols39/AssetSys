<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialBorrowingService;

header('Content-Type: application/json; charset=utf-8');

function materialBorrowingDetailsResponse(int $status, bool $success, string $message, array $data = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    materialBorrowingDetailsResponse(405, false, 'Method not allowed.');
}

try {
    AuthMiddleware::handle();
    $authorization = new AuthorizationService();
    if ($authorization->currentUser() === null) {
        materialBorrowingDetailsResponse(401, false, 'Authentication required.');
    }
    if (!$authorization->isPropertyCustodian()) {
        materialBorrowingDetailsResponse(403, false, 'You do not have permission to view borrowing records.');
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) {
        materialBorrowingDetailsResponse(422, false, 'Invalid borrowing record.');
    }

    $details = (new MaterialBorrowingService())->findDetails((int) $id);
    if (!$details) {
        materialBorrowingDetailsResponse(404, false, 'Borrowing record not found.');
    }

    materialBorrowingDetailsResponse(200, true, 'Borrowing details loaded.', ['borrowing' => $details]);
} catch (Throwable $e) {
    error_log('Material borrowing details API error: ' . $e->getMessage());
    materialBorrowingDetailsResponse(500, false, 'Unable to load borrowing details.');
}
