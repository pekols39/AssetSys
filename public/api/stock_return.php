<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\StockService;

function stockReturnJsonResponse(
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

    stockReturnJsonResponse(
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
        stockReturnJsonResponse(
            401,
            false,
            'Authentication required.'
        );
    }

    if (
        !$authorization->isAssetManager()
        && !$authorization->isAssetCustodian()
    ) {
        stockReturnJsonResponse(
            403,
            false,
            'You do not have permission to return materials.'
        );
    }

    if ($authorization->departmentId() === null) {
        stockReturnJsonResponse(
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
        stockReturnJsonResponse(
            419,
            false,
            'Invalid or expired CSRF token. Refresh the page and try again.'
        );
    }

    $materialId = filter_var(
        $_POST['material_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $materialId === false
        || $materialId === null
        || $materialId <= 0
    ) {
        stockReturnJsonResponse(
            422,
            false,
            'Please select a valid material.'
        );
    }


    $originalMovementId = filter_var(
        $_POST['original_movement_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $originalMovementId === false
        || $originalMovementId === null
        || $originalMovementId <= 0
    ) {
        stockReturnJsonResponse(
            422,
            false,
            'Please select a valid original stock-out transaction.'
        );
    }

    $quantityInput = $_POST['quantity'] ?? null;

    if (
        !is_string($quantityInput)
        && !is_int($quantityInput)
        && !is_float($quantityInput)
    ) {
        stockReturnJsonResponse(
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
        stockReturnJsonResponse(
            422,
            false,
            'Quantity must be greater than zero and have no more than three decimal places.'
        );
    }


    $referenceNumber = $_POST['reference_number'] ?? '';

    if (!is_string($referenceNumber)) {
        stockReturnJsonResponse(
            422,
            false,
            'Invalid reference number.'
        );
    }

    $referenceNumber = trim($referenceNumber);

    if (mb_strlen($referenceNumber) > 100) {
        stockReturnJsonResponse(
            422,
            false,
            'Reference number must not exceed 100 characters.'
        );
    }

    $notes = $_POST['notes'] ?? '';

    if (!is_string($notes)) {
        stockReturnJsonResponse(
            422,
            false,
            'Invalid notes.'
        );
    }

    $notes = trim($notes);

    if (mb_strlen($notes) > 1000) {
        stockReturnJsonResponse(
            422,
            false,
            'Notes must not exceed 1000 characters.'
        );
    }


    $movementId = (new StockService())->returnStock(
        materialId: (int) $materialId,
        quantity: (float) $quantityString,
        originalMovementId: (int) $originalMovementId,
        referenceNumber: $referenceNumber !== ''
        ? $referenceNumber
        : null,
        notes: $notes !== ''
        ? $notes
        : null
    );


    stockReturnJsonResponse(
        201,
        true,
        'Materials returned successfully.',
        [
            'movement_id' => $movementId,
        ]
    );
} catch (\RuntimeException $e) {
    stockReturnJsonResponse(
        422,
        false,
        $e->getMessage()
    );
} catch (\Throwable $e) {
    error_log(
        'Stock Return API error: ' . $e->getMessage()
    );

    stockReturnJsonResponse(
        500,
        false,
        'An unexpected error occurred while recording the material return.'
    );
}
