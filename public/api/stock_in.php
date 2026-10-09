<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\StockService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stockInJsonResponse(
    int $status,
    bool $success,
    string $message,
    array $data = []
): never {
    http_response_code($status);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => (object) $data,
        ],
        JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    stockInJsonResponse(
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
        stockInJsonResponse(
            401,
            false,
            'Authentication required.'
        );
    }

    if (
        !$authorization->isAssetManager()
        && !$authorization->isAssetCustodian()
    ) {
        stockInJsonResponse(
            403,
            false,
            'You do not have permission to receive stock.'
        );
    }

    if ($authorization->departmentId() === null) {
        stockInJsonResponse(
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
        stockInJsonResponse(
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
        stockInJsonResponse(
            422,
            false,
            'Please select a valid material.'
        );
    }

    $quantityInput = trim(
        (string) ($_POST['quantity'] ?? '')
    );

    if (
        $quantityInput === ''
        || !is_numeric($quantityInput)
    ) {
        stockInJsonResponse(
            422,
            false,
            'Enter a valid quantity.'
        );
    }

    $quantity = (float) $quantityInput;

    if (
        !is_finite($quantity)
        || $quantity <= 0
        || round($quantity, 3) != $quantity
    ) {
        stockInJsonResponse(
            422,
            false,
            'Quantity must be greater than zero and have no more than three decimal places.'
        );
    }

    $vendorInput = trim(
        (string) ($_POST['vendor_id'] ?? '')
    );

    $vendorId = null;

    if ($vendorInput !== '') {
        $validatedVendorId = filter_var(
            $vendorInput,
            FILTER_VALIDATE_INT
        );

        if (
            $validatedVendorId === false
            || $validatedVendorId <= 0
        ) {
            stockInJsonResponse(
                422,
                false,
                'Please select a valid vendor.'
            );
        }

        $vendorId = $validatedVendorId;
    }

    $referenceNumber = trim(
        (string) ($_POST['reference_number'] ?? '')
    );

    $notes = trim(
        (string) ($_POST['notes'] ?? '')
    );

    if (strlen($referenceNumber) > 100) {
        stockInJsonResponse(
            422,
            false,
            'Reference number cannot exceed 100 characters.'
        );
    }

    $movementId = (new StockService())->stockIn(
        materialId: $materialId,
        quantity: $quantity,
        vendorId: $vendorId,
        referenceNumber: $referenceNumber !== ''
        ? $referenceNumber
        : null,
        notes: $notes !== ''
        ? $notes
        : null
    );

    stockInJsonResponse(
        201,
        true,
        'Stock received successfully.',
        [
            'movement_id' => $movementId,
        ]
    );

} catch (\RuntimeException $exception) {
    stockInJsonResponse(
        422,
        false,
        $exception->getMessage()
    );

} catch (\Throwable $exception) {
    error_log(
        'Stock-in operation failed: '
        . $exception->getMessage()
    );

    stockInJsonResponse(
        500,
        false,
        'Unable to receive stock due to a server error.'
    );
}