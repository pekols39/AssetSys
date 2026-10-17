<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetAssignmentService;
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
            'message' => 'You do not have permission to perform this action.',
        ]);

        exit;
    }

    if (
        !Csrf::validate(
            $_POST[CSRF_TOKEN_NAME] ?? null
        )
    ) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Your session or security token has expired. Please refresh the page and try again.',
        ]);

        exit;
    }

    $returnedTo = Auth::id();

    if ($returnedTo === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }

    $assignmentId = filter_var(
        $_POST['assignment_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$assignmentId || $assignmentId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid assignment ID.',
        ]);

        exit;
    }

    $remarks =
        isset($_POST['remarks'])
        ? (string) $_POST['remarks']
        : null;

    $assignmentService =
        new AssetAssignmentService();

    $assignmentService->returnAssignment(
        (int) $assignmentId,
        (int) $returnedTo,
        $remarks
    );

    echo json_encode([
        'success' => true,
        'message' => 'Asset returned successfully.',
    ]);

    exit;

} catch (RuntimeException $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

    exit;

} catch (\Throwable $e) {

    if (
        defined('APP_DEBUG')
        && APP_DEBUG === true
    ) {
        error_log(
            'Asset return error: '
            . $e->getMessage()
        );
    } else {
        error_log(
            'Asset return error.'
        );
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to return the asset at this time.',
    ]);

    exit;
}