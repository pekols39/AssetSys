<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\PhysicalStockAuditService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function physicalAuditCreateJsonResponse(
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

    physicalAuditCreateJsonResponse(
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
        physicalAuditCreateJsonResponse(
            419,
            false,
            'Invalid or expired security token. Refresh the page and try again.'
        );
    }

    $auditType = trim(
        (string) ($_POST['audit_type'] ?? 'consumable')
    );

    if ($auditType !== 'consumable') {
        physicalAuditCreateJsonResponse(
            422,
            false,
            'Only consumable materials can be physically counted.'
        );
    }

    $auditDateInput = trim(
        (string) ($_POST['audit_date'] ?? '')
    );

    $auditDate = \DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $auditDateInput
    );

    if (
        $auditDate === false
        || $auditDate->format('Y-m-d\TH:i') !== $auditDateInput
    ) {
        physicalAuditCreateJsonResponse(
            422,
            false,
            'Please enter a valid audit date and time.'
        );
    }

    $auditNotes = trim(
        (string) ($_POST['audit_notes'] ?? '')
    );

    if (strlen($auditNotes) > 2000) {
        physicalAuditCreateJsonResponse(
            422,
            false,
            'Audit notes cannot exceed 2,000 characters.'
        );
    }

    $submittedItems = $_POST['items'] ?? [];

    if (!is_array($submittedItems)) {
        physicalAuditCreateJsonResponse(
            422,
            false,
            'Invalid audit item data.'
        );
    }

    foreach ($submittedItems as $item) {
        if (!is_array($item)) {
            physicalAuditCreateJsonResponse(
                422,
                false,
                'Invalid audit item data.'
            );
        }
    }

    $auditId = (new PhysicalStockAuditService())->createAudit(
        auditType: $auditType,
        auditDate: $auditDate->format('Y-m-d H:i:s'),
        submittedItems: $submittedItems,
        auditNotes: $auditNotes !== '' ? $auditNotes : null,
        includeAll: ($_POST['include_all'] ?? '') === '1'
    );

    physicalAuditCreateJsonResponse(
        201,
        true,
        'Physical count recorded successfully.',
        [
            'audit_id' => $auditId,
        ]
    );

} catch (\RuntimeException $exception) {
    physicalAuditCreateJsonResponse(
        422,
        false,
        $exception->getMessage()
    );

} catch (\Throwable $exception) {
    error_log(
        'Physical stock audit creation failed: '
        . $exception->getMessage()
    );

    physicalAuditCreateJsonResponse(
        500,
        false,
        'Unable to record the physical count due to a server error.'
    );
}
