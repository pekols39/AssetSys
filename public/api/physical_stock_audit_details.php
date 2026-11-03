<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\AuthorizationService;
use App\Services\PhysicalStockAuditService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    if (!$authorization->canAccessAssetOperations()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to view physical stock audits.',
        ]);

        exit;
    }

    $auditId = filter_var(
        $_GET['audit_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$auditId || $auditId <= 0) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid physical stock audit.',
        ]);

        exit;
    }

    $service = new PhysicalStockAuditService();
    $audit = $service->getAuditDetails((int) $auditId);

    echo json_encode([
        'success' => true,
        'data' => $audit,
    ]);

    exit;

} catch (\PDOException $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'Physical stock audit details database error: '
            . $e->getMessage()
        );
    } else {
        error_log('Physical stock audit details database error.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load physical stock audit details.',
    ]);

    exit;

} catch (\RuntimeException $e) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

    exit;

} catch (\Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log(
            'Physical stock audit details failed: '
            . $e->getMessage()
        );
    } else {
        error_log('Physical stock audit details failed.');
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load physical stock audit details.',
    ]);

    exit;
}