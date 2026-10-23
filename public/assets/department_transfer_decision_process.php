<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetDepartmentTransferService;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);

    exit;
}

try {
    AuthMiddleware::handle();

    $authorization = new AuthorizationService();

    if (!$authorization->canAccessAssetMaterialAreas()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to review department-transfer requests.',
        ]);

        exit;
    }

    $csrfToken =
        $_POST[CSRF_TOKEN_NAME]
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!Csrf::validate($csrfToken)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Your security token has expired. Refresh the page and try again.',
        ]);

        exit;
    }

    $reviewedBy = Auth::id();

    if ($reviewedBy === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }

    $requestId = filter_var(
        $_POST['request_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$requestId || $requestId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid department-transfer request.',
        ]);

        exit;
    }

    $decision = $_POST['decision'] ?? '';

    if (!is_string($decision)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid decision.',
        ]);

        exit;
    }

    $decision = strtolower(trim($decision));

    $reviewNotes = $_POST['review_notes'] ?? '';

    if (!is_string($reviewNotes)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid review notes.',
        ]);

        exit;
    }

    $reviewNotes = trim($reviewNotes);

    if (mb_strlen($reviewNotes) > 500) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Review notes must not exceed 500 characters.',
        ]);

        exit;
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Choose a valid approval or rejection decision.',
        ]);

        exit;
    }

    if ($decision === 'reject' && $reviewNotes === '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'A reason is required when rejecting a transfer request.',
        ]);

        exit;
    }

    $service = new AssetDepartmentTransferService();

    if ($decision === 'approve') {
        $service->approve(
            (int) $requestId,
            (int) $reviewedBy,
            $reviewNotes !== '' ? $reviewNotes : null
        );

        $message = 'Department-transfer request approved successfully.';
    } else {
        $service->reject(
            (int) $requestId,
            (int) $reviewedBy,
            $reviewNotes
        );

        $message = 'Department-transfer request rejected successfully.';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'request_id' => (int) $requestId,
        'decision' => $decision,
    ]);

    exit;

} catch (\PDOException $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'Department-transfer decision database error: '
            . $e->getMessage()
        );
    } else {
        error_log('Department-transfer decision database error.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to process the department-transfer decision.',
    ]);

    exit;

} catch (\RuntimeException $e) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

    exit;

} catch (\Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'Department-transfer decision failed: '
            . $e->getMessage()
        );
    } else {
        error_log('Department-transfer decision failed.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to process the department-transfer decision.',
    ]);

    exit;
}