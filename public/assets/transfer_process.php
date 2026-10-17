<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetTransferService;
use App\Services\AuthorizationService;
use App\Exceptions\AssetTransferInternalException;

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

    $transferredBy = Auth::id();

    if ($transferredBy === null) {
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

    if (!$assetId || $assetId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid asset ID.',
        ]);

        exit;
    }

    $destinationLocationName = trim(
        (string) ($_POST['destination_location_name'] ?? '')
    );

    if ($destinationLocationName === '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Please enter a destination location.',
        ]);

        exit;
    }

    if (mb_strlen($destinationLocationName) > 150) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Destination location must not exceed 150 characters.',
        ]);

        exit;
    }

    $transferReason =
        isset($_POST['transfer_reason'])
        ? (string) $_POST['transfer_reason']
        : null;

    $transferReference =
        isset($_POST['transfer_reference'])
        ? (string) $_POST['transfer_reference']
        : null;

    $remarks =
        isset($_POST['remarks'])
        ? (string) $_POST['remarks']
        : null;

    $transferService =
        new AssetTransferService();

    $destinationDepartmentName = trim(
        (string) ($_POST['destination_department_name'] ?? '')
    );

    $destinationDepartmentName = $destinationDepartmentName !== ''
        ? $destinationDepartmentName
        : null;

    $assignedToName = trim(
        (string) ($_POST['assigned_to_name'] ?? '')
    );

    $assignedToName = $assignedToName !== ''
        ? $assignedToName
        : null;

    $transferId = $transferService->transfer(
        (int) $assetId,
        $destinationLocationName,
        $destinationDepartmentName,
        $assignedToName,
        (int) $transferredBy,
        $transferReason,
        $transferReference,
        $remarks
    );


    echo json_encode([
        'success' => true,
        'message' => 'Asset transferred successfully.',
        'transfer_id' => $transferId,
        'asset_id' => (int) $assetId,
    ]);

    exit;


} catch (AssetTransferInternalException $e) {
    error_log(
        'Asset transfer error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to transfer the asset at this time.',
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
            'Asset transfer error: '
            . $e->getMessage()
        );
    } else {
        error_log(
            'Asset transfer error.'
        );
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to transfer the asset at this time.',
    ]);

    exit;
}