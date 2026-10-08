<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
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
    AuthMiddleware::handle();

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

    $id = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$id || $id <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid asset ID.',
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

    $assetService = new AssetService();
    $asset = $assetService->findById((int) $id);

    if ($asset === false) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Asset not found.',
        ]);

        exit;
    }

    if (
        !$authorization->isAssetManager()
        && !$authorization->isAssetCustodian()
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to edit assets.',
        ]);

        exit;
    }

    $userDepartmentId = $authorization->departmentId();

    $assetDepartmentId = $asset['department_id'] === null
        ? null
        : (int) $asset['department_id'];

    if (
        $userDepartmentId === null
        || $assetDepartmentId === null
        || $userDepartmentId !== $assetDepartmentId
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'You may only edit assets assigned to your department.',
        ]);

        exit;
    }

    // Department ownership changes must use the controlled
    // transfer workflow, not the ordinary asset edit form.
    $_POST['department_id'] = $asset['department_id'] === null
        ? null
        : (int) $asset['department_id'];

    $assetService->update(
        (int) $id,
        $_POST
    );

    echo json_encode([
        'success' => true,
        'message' => 'Asset updated successfully.',
        'asset_id' => (int) $id,
        'redirect' => base_url(
            '/public/assets/view.php?id='
            . (int) $id
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
    if (
        defined('APP_DEBUG')
        && APP_DEBUG === true
    ) {
        error_log(
            'Asset update error: '
            . $e->getMessage()
        );
    } else {
        error_log(
            'Asset update error.'
        );
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update the asset at this time.',
    ]);

    exit;
}