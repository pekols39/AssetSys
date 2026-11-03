<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\MaterialReplenishmentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function replenishmentJsonResponse(
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

    replenishmentJsonResponse(
        405,
        false,
        'Method not allowed.'
    );
}

try {
    AuthMiddleware::handle();

    $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? null;

    if (
        !is_string($csrfToken)
        || !Csrf::validate($csrfToken)
    ) {
        replenishmentJsonResponse(
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
        replenishmentJsonResponse(422, false, 'Please select a valid material.');
    }

    $quantityInput = trim(
        (string) ($_POST['quantity'] ?? '')
    );

    if (
        $quantityInput === ''
        || !is_numeric($quantityInput)
    ) {
        replenishmentJsonResponse(
            422,
            false,
            'Enter a valid replenishment quantity.'
        );
    }

    $quantity = (float) $quantityInput;

    if (
        !is_finite($quantity)
        || $quantity <= 0
        || round($quantity, 3) != $quantity
    ) {
        replenishmentJsonResponse(
            422,
            false,
            'Quantity must be greater than zero and have no more than three decimal places.'
        );
    }

    $replenishedAtInput = trim(
        (string) ($_POST['replenished_at'] ?? '')
    );

    $replenishedAt = \DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $replenishedAtInput
    );

    if (
        $replenishedAt === false
        || $replenishedAt->format('Y-m-d\TH:i') !== $replenishedAtInput
    ) {
        replenishmentJsonResponse(
            422,
            false,
            'Please enter a valid replenishment date and time.'
        );
    }

    $referenceNumber = trim(
        (string) ($_POST['reference_number'] ?? '')
    );

    $notes = trim(
        (string) ($_POST['notes'] ?? '')
    );

    if (strlen($referenceNumber) > 100) {
        replenishmentJsonResponse(
            422,
            false,
            'Reference number cannot exceed 100 characters.'
        );
    }

    $recordId = (new MaterialReplenishmentService())->create(
        materialId: $materialId,
        quantity: $quantity,
        replenishedAt: $replenishedAt->format('Y-m-d H:i:s'),
        referenceNumber: $referenceNumber !== ''
        ? $referenceNumber
        : null,
        notes: $notes !== ''
        ? $notes
        : null
    );

    replenishmentJsonResponse(
        201,
        true,
        'Replenishment history recorded successfully. Stock balances were not changed.',
        [
            'replenishment_id' => $recordId,
        ]
    );

} catch (\RuntimeException $exception) {
    replenishmentJsonResponse(
        422,
        false,
        $exception->getMessage()
    );

} catch (\Throwable $exception) {
    error_log(
        'Material replenishment history failed: '
        . $exception->getMessage()
    );

    replenishmentJsonResponse(
        500,
        false,
        'Unable to record replenishment history due to a server error.'
    );
}