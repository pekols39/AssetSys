<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Helpers\Csrf;
use App\Middleware\AuthMiddleware;
use App\Services\RepairService;

header('Content-Type: application/json; charset=utf-8');

try {
    AuthMiddleware::handle();

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

    $repairId = (int) ($_POST['repair_id'] ?? 0);

    if ($repairId <= 0) {
        throw new RuntimeException('Invalid repair record.');
    }

    $repairService = new RepairService();

    $repairService->update(
        $repairId,
        (int) Auth::id(),
        $_POST
    );

    echo json_encode([
        'success' => true,
        'message' => 'Repair record updated successfully.',
        'repair_id' => $repairId,
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}