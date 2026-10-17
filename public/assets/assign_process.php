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

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'message' => 'Your session or security token has expired. Please refresh the page and try again.',
        ]);
        exit;
    }

    $assignedBy = Auth::id();

    if ($assignedBy === null) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);
        exit;
    }

    $assetId = filter_var(
        $_POST['asset_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($assetId === false || $assetId === null || $assetId <= 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid asset ID.',
        ]);
        exit;
    }

    $assignedToName = trim(
        (string) ($_POST['assigned_to_name'] ?? '')
    );

    if ($assignedToName === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please enter the employee name.',
        ]);
        exit;
    }

    if (mb_strlen($assignedToName) > 150) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Employee name must not exceed 150 characters.',
        ]);
        exit;
    }

    $acknowledgementReference = isset($_POST['acknowledgement_reference'])
        ? trim((string) $_POST['acknowledgement_reference'])
        : null;

    $remarks = isset($_POST['remarks'])
        ? trim((string) $_POST['remarks'])
        : null;

    $assignmentService = new AssetAssignmentService();

    $assignmentId = $assignmentService->assign(
        (int) $assetId,
        $assignedToName,
        (int) $assignedBy,
        $acknowledgementReference,
        $remarks
    );

    echo json_encode([
        'success' => true,
        'message' => 'Asset assigned successfully.',
        'assignment_id' => $assignmentId,
        'asset_id' => (int) $assetId,
        'redirect' => base_url(
            '/public/assets/view.php?id=' . (int) $assetId
        ),
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
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log('Asset assignment error: ' . $e->getMessage());
    } else {
        error_log('Asset assignment error.');
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to assign the asset at this time.',
    ]);
    exit;
}
