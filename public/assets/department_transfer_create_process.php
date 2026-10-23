<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\AssetDepartmentTransferService;

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

    $csrfToken =
        $_POST[CSRF_TOKEN_NAME]
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!Csrf::validate($csrfToken)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' =>
                'Your security token has expired. Refresh the page and try again.',
        ]);

        exit;
    }

    $requestedBy = Auth::id();

    if ($requestedBy === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }

    $assetId = filter_input(
        INPUT_POST,
        'asset_id',
        FILTER_VALIDATE_INT
    );

    $destinationDepartmentName =
        $_POST['destination_department_name'] ?? '';

    if (!is_string($destinationDepartmentName)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid destination department.',
        ]);

        exit;
    }

    $destinationDepartmentName = trim($destinationDepartmentName);

    if ($destinationDepartmentName === '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Enter the destination department.',
        ]);

        exit;
    }

    if (mb_strlen($destinationDepartmentName) > 150) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' =>
                'The destination department must not exceed 150 characters.',
        ]);

        exit;
    }

    if (!$assetId || $assetId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid asset.',
        ]);

        exit;
    }

    $requestReason = $_POST['request_reason'] ?? '';

    if (!is_string($requestReason)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid transfer reason.',
        ]);

        exit;
    }

    $service = new AssetDepartmentTransferService();

    $requestId = $service->requestTransfer(
        (int) $assetId,
        $destinationDepartmentName,
        (int) $requestedBy,
        $requestReason
    );

    echo json_encode([
        'success' => true,
        'message' =>
            'Department-transfer request submitted successfully. It is pending Asset Manager approval.',
        'transfer_id' => $requestId,
    ]);

    exit;

} catch (\PDOException $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'Department transfer request database error: '
            . $e->getMessage()
        );
    } else {
        error_log('Department transfer request database error.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Unable to complete the department transfer.',
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
        error_log(
            'Department transfer request failed: '
            . $e->getMessage()
        );
    } else {
        error_log('Department transfer request failed.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Unable to complete the department transfer.',
    ]);

    exit;
}