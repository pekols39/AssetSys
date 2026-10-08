<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Middleware\AuthMiddleware;
use App\Helpers\Csrf;
use App\Services\MaintenanceService;

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

    if (
        !Csrf::validate(
            $_POST[CSRF_TOKEN_NAME] ?? null
        )
    ) {
        http_response_code(419);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired security token.',
        ]);

        exit;
    }

    $maintenanceId = (int) (
        $_POST['id'] ?? 0
    );

    if ($maintenanceId <= 0) {
        throw new RuntimeException(
            'Invalid maintenance record.'
        );
    }

    $updatedBy = Auth::id();

    if ($updatedBy <= 0) {
        throw new RuntimeException(
            'Unable to identify the current user.'
        );
    }

    $service = new MaintenanceService();

    $data = [
        'asset_id' => (int) (
            $_POST['asset_id'] ?? 0
        ),

        'maintenance_type' => trim(
            (string) (
                $_POST['maintenance_type'] ?? ''
            )
        ),

        'title' => trim(
            (string) (
                $_POST['title'] ?? ''
            )
        ),

        'description' => $_POST['description']
            ?? null,

        'service_provider_id' =>
            $_POST['service_provider_id']
            ?? null,

        'scheduled_date' =>
            $_POST['scheduled_date']
            ?? null,

        'started_at' =>
            $_POST['started_at']
            ?? null,

        'completed_at' =>
            $_POST['completed_at']
            ?? null,

        'maintenance_status' =>
            $_POST['maintenance_status']
            ?? 'scheduled',

        'cost' =>
            $_POST['cost']
            ?? 0,

        'reference_number' =>
            $_POST['reference_number']
            ?? null,

        'findings' =>
            $_POST['findings']
            ?? null,

        'actions_taken' =>
            $_POST['actions_taken']
            ?? null,

        'recommendations' =>
            $_POST['recommendations']
            ?? null,

        'next_maintenance_date' =>
            $_POST['next_maintenance_date']
            ?? null,
    ];

    $service->updateWithUser(
        $maintenanceId,
        $data,
        $updatedBy
    );

    echo json_encode([
        'success' => true,
        'message' => 'Maintenance record updated successfully.',
    ]);

} catch (RuntimeException $e) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

} catch (Throwable $e) {
    error_log(
        'AssetSys maintenance update error: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update maintenance record.',
    ]);
}