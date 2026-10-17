<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\RepairService;
use App\Services\AuthorizationService;

header('Content-Type: application/json; charset=utf-8');

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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    if (!Csrf::validate($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired security token.',
        ]);

        exit;
    }

    $assetId = (int) ($_POST['asset_id'] ?? 0);

    if ($assetId <= 0) {
        throw new RuntimeException('Invalid asset.');
    }

    $repairService = new RepairService();

    $repairId = $repairService->create(
        $assetId,
        (int) Auth::id(),
        $_POST
    );

    echo json_encode([
        'success' => true,
        'message' => 'Repair record created successfully.',
        'repair_id' => $repairId,
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}