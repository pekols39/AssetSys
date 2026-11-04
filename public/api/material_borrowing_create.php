<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\MaterialBorrowingService;

function materialBorrowingCreateResponse(int $status, bool $success, string $message, array $data = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    materialBorrowingCreateResponse(405, false, 'Method not allowed.');
}

try {
    AuthMiddleware::handle();
    $authorization = new AuthorizationService();
    if ($authorization->currentUser() === null) {
        materialBorrowingCreateResponse(401, false, 'Authentication required.');
    }
    if (!$authorization->isPropertyCustodian()) {
        materialBorrowingCreateResponse(403, false, 'You do not have permission to create borrowings.');
    }

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;
    if (!is_string($csrfToken) || !Csrf::validate($csrfToken)) {
        materialBorrowingCreateResponse(419, false, 'Invalid or expired CSRF token. Refresh the page and try again.');
    }

    $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
    if ($materialId === false || $materialId === null || $materialId <= 0) {
        materialBorrowingCreateResponse(422, false, 'Please select a valid returnable material.');
    }

    $stringFields = ['borrower_name', 'quantity', 'location', 'purpose', 'borrowed_at', 'expected_return_date'];
    foreach ($stringFields as $field) {
        if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
            materialBorrowingCreateResponse(422, false, 'Please complete all required borrowing fields.');
        }
    }
    foreach (['reference_number', 'notes'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            materialBorrowingCreateResponse(422, false, 'Invalid optional field.');
        }
    }

    $id = (new MaterialBorrowingService())->createBorrowing(
        (int) $materialId,
        $_POST['borrower_name'],
        $_POST['quantity'],
        $_POST['location'],
        $_POST['purpose'],
        $_POST['borrowed_at'],
        $_POST['expected_return_date'],
        $_POST['reference_number'] ?? null,
        $_POST['notes'] ?? null
    );

    materialBorrowingCreateResponse(201, true, 'Borrowing recorded and available stock updated successfully.', ['borrowing_id' => $id]);
} catch (\PDOException $e) {
    error_log('Material borrowing database error: ' . $e->getMessage());
    materialBorrowingCreateResponse(500, false, 'A database error occurred while creating a borrowing.');
} catch (\RuntimeException $e) {
    materialBorrowingCreateResponse(422, false, $e->getMessage());
} catch (\Throwable $e) {
    error_log('Material borrowing API error: ' . $e->getMessage());
    materialBorrowingCreateResponse(500, false, 'An unexpected error occurred while creating a borrowing.');
}
