<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\StockService;

function stockOutJsonResponse(
    int $status,
    bool $success,
    string $message,
    array $data = []
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message,
        ], $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    stockOutJsonResponse(
        405,
        false,
        'Method not allowed.'
    );
}

try {
    AuthMiddleware::handle();

    $authorization = new AuthorizationService();
    $currentUser = $authorization->currentUser();

    if ($currentUser === null) {
        stockOutJsonResponse(
            401,
            false,
            'Authentication required.'
        );
    }

    if (
        !$authorization->isAssetManager()
        && !$authorization->isAssetCustodian()
    ) {
        stockOutJsonResponse(
            403,
            false,
            'You do not have permission to issue materials.'
        );
    }

    if ($authorization->departmentId() === null) {
        stockOutJsonResponse(
            403,
            false,
            'Your account must be assigned to a department.'
        );
    }

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

    if (
        !is_string($csrfToken)
        || !Csrf::validate($csrfToken)
    ) {
        stockOutJsonResponse(
            419,
            false,
            'Invalid or expired CSRF token. Refresh the page and try again.'
        );
    }

    $materialId = filter_var(
        $_POST['material_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($materialId === false || $materialId === null || $materialId <= 0) {
        stockOutJsonResponse(
            422,
            false,
            'Please select a valid material.'
        );
    }

    $quantityInput = $_POST['quantity'] ?? null;

    if (
        !is_string($quantityInput)
        && !is_int($quantityInput)
        && !is_float($quantityInput)
    ) {
        stockOutJsonResponse(
            422,
            false,
            'Please enter a valid quantity.'
        );
    }

    $quantityString = trim((string) $quantityInput);

    if (
        $quantityString === ''
        || !is_numeric($quantityString)
        || !is_finite((float) $quantityString)
        || (float) $quantityString <= 0
        || (float) $quantityString > 99999999999.999
        || preg_match('/^\d+(?:\.\d{1,3})?$/', $quantityString) !== 1
    ) {
        stockOutJsonResponse(
            422,
            false,
            'Quantity must be greater than zero and have no more than three decimal places.'
        );
    }

    $recipientInput = $_POST['issued_to_name'] ?? null;

    if (!is_string($recipientInput)) {
        stockOutJsonResponse(
            422,
            false,
            'Recipient name is required.'
        );
    }

    $recipientName = trim($recipientInput);

    if ($recipientName === '') {
        stockOutJsonResponse(
            422,
            false,
            'Recipient name is required.'
        );
    }

    if (mb_strlen($recipientName) > 255) {
        stockOutJsonResponse(
            422,
            false,
            'Recipient name must not exceed 255 characters.'
        );
    }

    $purpose = $_POST['purpose'] ?? null;

    if (!is_string($purpose)) {
        stockOutJsonResponse(
            422,
            false,
            'Purpose is required.'
        );
    }

    $purpose = trim($purpose);

    if ($purpose === '' || mb_strlen($purpose) > 255) {
        stockOutJsonResponse(
            422,
            false,
            'Purpose is required and must not exceed 255 characters.'
        );
    }

    $referenceNumber = $_POST['reference_number'] ?? '';

    if (!is_string($referenceNumber)) {
        stockOutJsonResponse(
            422,
            false,
            'Invalid reference number.'
        );
    }

    $referenceNumber = trim($referenceNumber);

    if (mb_strlen($referenceNumber) > 100) {
        stockOutJsonResponse(
            422,
            false,
            'Reference number must not exceed 100 characters.'
        );
    }

    $notes = $_POST['notes'] ?? '';

    if (!is_string($notes)) {
        stockOutJsonResponse(
            422,
            false,
            'Invalid notes.'
        );
    }

    $notes = trim($notes);

    if (mb_strlen($notes) > 1000) {
        stockOutJsonResponse(
            422,
            false,
            'Notes must not exceed 1000 characters.'
        );
    }

    $movementId = (new StockService())->stockOut(
        materialId: (int) $materialId,
        quantity: (float) $quantityString,
        issuedToUserId: null,
        issuedToName: $recipientName,
        purpose: $purpose,
        referenceNumber: $referenceNumber !== ''
        ? $referenceNumber
        : null,
        notes: $notes !== ''
        ? $notes
        : null
    );

    stockOutJsonResponse(
        201,
        true,
        'Materials issued successfully.',
        [
            'movement_id' => $movementId,
        ]
    );
} catch (\RuntimeException $e) {
    stockOutJsonResponse(
        422,
        false,
        $e->getMessage()
    );
} catch (\Throwable $e) {
    error_log(
        'Stock-Out API error: ' . $e->getMessage()
    );

    stockOutJsonResponse(
        500,
        false,
        'An unexpected error occurred while issuing materials.'
    );
}