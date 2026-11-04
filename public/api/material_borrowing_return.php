<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialBorrowingService;

function materialBorrowingReturnResponse(int $status, bool $success, string $message, array $data = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    materialBorrowingReturnResponse(405, false, 'Method not allowed.');
}

try {
    AuthMiddleware::handle();
    $authorization = new AuthorizationService();
    if ($authorization->currentUser() === null) {
        materialBorrowingReturnResponse(401, false, 'Authentication required.');
    }
    if (!$authorization->isPropertyCustodian()) {
        materialBorrowingReturnResponse(403, false, 'You do not have permission to record returns.');
    }

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;
    if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
        materialBorrowingReturnResponse(419, false, 'Invalid or expired CSRF token. Refresh the page and try again.');
    }

    $borrowingId = filter_var($_POST['borrowing_id'] ?? null, FILTER_VALIDATE_INT);
    if ($borrowingId === false || $borrowingId === null || $borrowingId <= 0) {
        materialBorrowingReturnResponse(422, false, 'Invalid borrowing record.');
    }

    foreach (['quantity', 'returned_at', 'item_condition'] as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            materialBorrowingReturnResponse(422, false, 'Please complete all required return fields.');
        }
    }
    foreach (['condition_notes', 'notes'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            materialBorrowingReturnResponse(422, false, 'Invalid optional field.');
        }
    }

    $id = (new MaterialBorrowingService())->recordReturn(
        (int) $borrowingId,
        $_POST['quantity'],
        $_POST['returned_at'],
        $_POST['item_condition'],
        $_POST['condition_notes'] ?? null,
        $_POST['notes'] ?? null
    );

    materialBorrowingReturnResponse(201, true, 'Return recorded and available stock updated successfully.', ['return_id' => $id]);
} catch (\PDOException $e) {
    error_log('Material borrowing return database error: ' . $e->getMessage());
    materialBorrowingReturnResponse(500, false, 'A database error occurred while recording a return.');
} catch (\RuntimeException $e) {
    materialBorrowingReturnResponse(422, false, $e->getMessage());
} catch (\Throwable $e) {
    error_log('Material borrowing API error: ' . $e->getMessage());
    materialBorrowingReturnResponse(500, false, 'An unexpected error occurred while recording a return.');
}
