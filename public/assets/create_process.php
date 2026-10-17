<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Services\AssetService;
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
    \App\Middleware\AuthMiddleware::handle();

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

    $userId = Auth::id();

    if ($userId === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }


    $authorization = new AuthorizationService();
    $currentUser = $authorization->currentUser();

    if ($currentUser === null) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }

    if (!$authorization->canAccessAssetMaterialAreas()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to create assets.',
        ]);

        exit;
    }

    $assetService = new AssetService();

    $assetId = $assetService->create(
        $_POST,
        (int) $userId
    );

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Asset created successfully.',
        'asset_id' => $assetId,
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
            'Asset creation error: '
            . $e->getMessage()
        );
    } else {
        error_log(
            'Asset creation error.'
        );
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to create the asset at this time.',
    ]);

    exit;
}